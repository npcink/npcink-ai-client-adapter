import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const cliPath = join(dirname(fileURLToPath(import.meta.url)), '..', 'bin', 'npcink-openclaw-adapter.mjs');

const EXPECTED_TOOLS = [
  'health',
  'capabilities',
  'list_proposals',
  'proposal_status',
  'run_read_ability',
  'read_request_create',
  'read_request_status',
  'propose_write',
  'commit_preflight',
  'execute_approved',
];

function makeProfileDir() {
  const dir = mkdtempSync(join(tmpdir(), 'npcink-cli-contract-'));
  const profilePath = join(dir, 'contract.json');
  writeFileSync(profilePath, JSON.stringify({
    adapter_base_url: 'https://127.0.0.1.invalid',
    key_id: 'contract-test-key',
    private_key_jwk: { kty: 'OKP', crv: 'Ed25519', d: 'AA', x: 'AA' },
  }));
  return profilePath;
}

function startMcpServer(profilePath) {
  const child = spawn(process.execPath, [cliPath, 'mcp', `--profile-file=${profilePath}`], {
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  child.stderr.resume();
  return child;
}

function rpcCall(child, id, method, params) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`MCP call ${method} timed out`)), 15000);
    const onData = (chunk) => {
      for (const line of String(chunk).split('\n')) {
        if (!line.trim()) {
          continue;
        }
        let message;
        try {
          message = JSON.parse(line);
        } catch {
          continue;
        }
        if (message.id === id) {
          clearTimeout(timer);
          child.stdout.off('data', onData);
          resolve(message);
          return;
        }
      }
    };
    child.stdout.on('data', onData);
    child.once('exit', (code) => {
      clearTimeout(timer);
      reject(new Error(`MCP server exited early with code ${code}`));
    });
    child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', id, method, params })}\n`);
  });
}

test('mcp initialize completes the JSON-RPC handshake', async () => {
  const child = startMcpServer(makeProfileDir());
  try {
    const response = await rpcCall(child, 1, 'initialize', {
      protocolVersion: '2025-06-18',
      capabilities: {},
      clientInfo: { name: 'contract-test', version: '0.0.0' },
    });
    assert.equal(response.error, undefined);
    assert.equal(response.result.protocolVersion, '2025-06-18');
    assert.ok(response.result.serverInfo);
  } finally {
    child.kill();
  }
});

test('mcp tools/list exposes the governed tool table without approve_and_execute', async () => {
  const child = startMcpServer(makeProfileDir());
  try {
    const response = await rpcCall(child, 2, 'tools/list', {});
    assert.equal(response.error, undefined);
    const names = response.result.tools.map((tool) => tool.name).sort();
    assert.deepEqual(names, [...EXPECTED_TOOLS].sort());
    assert.equal(names.includes('approve_and_execute'), false);
    for (const tool of response.result.tools) {
      assert.equal(typeof tool.description, 'string', `${tool.name} must carry a description`);
      assert.equal(typeof tool.inputSchema, 'object', `${tool.name} must declare an input schema`);
      assert.equal(tool.inputSchema.additionalProperties, false, `${tool.name} must fail closed on undeclared arguments`);
    }
  } finally {
    child.kill();
  }
});

test('mcp execution tools refuse calls without the required intent', async () => {
  const child = startMcpServer(makeProfileDir());
  try {
    const missing = await rpcCall(child, 3, 'tools/call', { name: 'execute_approved', arguments: { proposal_id: 'abc123' } });
    assert.equal(missing.result.isError, true);
    assert.equal(missing.result.content[0].text.includes('invalid_params'), true);

    const wrong = await rpcCall(child, 4, 'tools/call', { name: 'execute_approved', arguments: { proposal_id: 'abc123', intent: 'preflight' } });
    assert.equal(wrong.result.isError, true);
    assert.equal(wrong.result.content[0].text.includes('invalid_intent'), true);

    const preflight = await rpcCall(child, 5, 'tools/call', { name: 'commit_preflight', arguments: { proposal_id: 'abc123' } });
    assert.equal(preflight.result.isError, true);
    assert.equal(preflight.result.content[0].text.includes('invalid_params'), true);
  } finally {
    child.kill();
  }
});

test('mcp tool calls fail closed on unknown tools and malformed input', async () => {
  const child = startMcpServer(makeProfileDir());
  try {
    const unknown = await rpcCall(child, 6, 'tools/call', { name: 'approve_and_execute', arguments: { proposal_id: 'abc123', intent: 'commit' } });
    assert.equal(unknown.result.isError, true);
    assert.equal(unknown.result.content[0].text.includes('unknown_tool'), true);

    const missingArgs = await rpcCall(child, 7, 'tools/call', { name: 'propose_write', arguments: {} });
    assert.equal(missingArgs.result.isError, true);
    assert.equal(missingArgs.result.content[0].text.includes('invalid_params'), true);

    const badId = await rpcCall(child, 8, 'tools/call', { name: 'proposal_status', arguments: { proposal_id: '../escape/attempts' } });
    assert.equal(badId.result.isError, true);
    assert.equal(badId.result.content[0].text.includes('invalid_params'), true);
  } finally {
    child.kill();
  }
});
