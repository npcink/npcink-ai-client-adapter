#!/usr/bin/env python3
"""Replay the real publisher fallback against an offline Git Data API simulator."""

import contextlib
import datetime
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


PUBLISHER = Path(__file__).resolve().parents[1] / "scripts/publish-pr.sh"
MARKER = '<<\'PY\'\nimport base64, json, subprocess, sys\n'
# Extract only the fallback: running the shell publisher itself would use the network.
SOURCE = "import base64, json, subprocess, sys\n" + PUBLISHER.read_text().split(
    MARKER, 1
)[1].split("\nPY\n", 1)[0]
MESSAGES = [
    b"Normal subject\n",
    "Subject\n\nFirst paragraph.\n\nSecond paragraph: 保真.\n\n\n".encode(),
    b"",
    b"Subject without final LF",
    b"\n\n",
]


def git(*args, **kwargs):
    return subprocess.check_output(["git", *args], **kwargs).decode().strip()


def identity(person):
    date = datetime.datetime.fromisoformat(person["date"])
    return f'{person["name"]} <{person["email"]}> {int(date.timestamp())} {date:%z}'


class PublisherMessageTest(unittest.TestCase):
    def replay(self, messages, corrupt=False):
        original_run = subprocess.run
        original_cwd = Path.cwd()
        # Do not inherit a caller's index, object directory, or Git configuration.
        env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
        env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull)
        with tempfile.TemporaryDirectory(prefix="publisher-message-") as directory, patch.dict(
            os.environ, env, clear=True
        ):
            os.chdir(directory)
            try:
                git("init", "-q", "--object-format=sha1")
                tree = git("mktree", input=b"")
                author = "作者 <author@example.invalid> 1700000000 +0800"
                committer = "Committer <committer@example.invalid> 1700000060 -0330"

                def commit(message, parent=None):
                    headers = f"tree {tree}\n"
                    if parent:
                        headers += f"parent {parent}\n"
                    raw = (headers + f"author {author}\ncommitter {committer}\n\n").encode()
                    return git("hash-object", "-t", "commit", "-w", "--stdin", input=raw + message)

                base = commit(b"Base\n")
                expected = []
                head = base
                for message in messages:
                    head = commit(message, head)
                    expected.append(head)
                git("update-ref", "HEAD", head)
                remote = base
                payloads = []
                patches = []
                prefix = "repos/offline/fixture"

                def api(command, **kwargs):
                    nonlocal remote
                    if command[0] == "git":
                        return original_run(command, **kwargs)
                    self.assertEqual(command[:2], ["gh", "api"])
                    method = command[3] if command[2] == "-X" else "GET"
                    path = command[4] if command[2] == "-X" else command[2]
                    payload = json.loads(kwargs["input"]) if kwargs.get("input") else None
                    if path == f"{prefix}/git/refs/heads/topic":
                        if method == "PATCH":
                            remote = payload["sha"]
                            patches.append(remote)
                        else:
                            self.assertEqual(method, "GET")
                        result = {"object": {"sha": remote}}
                    elif path.startswith(f"{prefix}/commits/") and method == "GET":
                        parent = path.rsplit("/", 1)[1]
                        result = {"commit": {"tree": {"sha": git("show", "-s", "--format=%T", parent)}}}
                    elif path == f"{prefix}/git/trees" and method == "POST":
                        # Empty commits isolate message fidelity from file/tree replay.
                        self.assertEqual(payload, {"base_tree": tree, "tree": []})
                        result = {"sha": tree}
                    elif path == f"{prefix}/git/commits" and method == "POST":
                        payloads.append(payload)
                        raw = f'tree {payload["tree"]}\n'
                        raw += "".join(f"parent {parent}\n" for parent in payload["parents"])
                        raw += f'author {identity(payload["author"])}\n'
                        raw += f'committer {identity(payload["committer"])}\n\n'
                        message = payload["message"].encode()
                        if corrupt:
                            message += b"corruption"
                        sha = git("hash-object", "-t", "commit", "-w", "--stdin", input=raw.encode() + message)
                        result = {"sha": sha}
                    else:
                        self.fail(f"Unexpected offline API call: {method} {path}")
                    return subprocess.CompletedProcess(command, 0, json.dumps(result).encode(), b"")

                output = io.StringIO()
                with patch.object(subprocess, "run", side_effect=api), patch.object(
                    sys, "argv", ["publisher-fallback", "offline/fixture", "topic"]
                ), contextlib.redirect_stdout(output):
                    exec(compile(SOURCE, str(PUBLISHER), "exec"), {"__name__": "__main__"})
                self.assertEqual([item["message"].encode() for item in payloads], messages)
                self.assertEqual(len(patches), 1)
                if corrupt:
                    self.assertNotEqual(remote, head)
                    self.assertEqual(output.getvalue().strip(), f"sha-mismatch {remote}")
                else:
                    self.assertEqual(remote, head)
                    self.assertEqual(patches, [expected[-1]])
                    self.assertEqual(output.getvalue().strip(), f"replayed-identical {head}")
            finally:
                os.chdir(original_cwd)

    def test_message_boundaries(self):
        for message in MESSAGES:
            with self.subTest(message=message):
                self.replay([message])

    def test_multiple_commits_keep_parent_chain(self):
        self.replay(MESSAGES)

    def test_sha_mismatch_is_still_reported(self):
        self.replay([b"No final LF"], corrupt=True)


if __name__ == "__main__":
    unittest.main(verbosity=2)
