import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:http';
import { generateKeyPairSync } from 'node:crypto';

const packageDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const cliPath = join(packageDir, 'bin', 'npcink-openclaw-adapter.mjs');
const requestWrapperPath = join(packageDir, 'bin', 'keypair-adapter-request.mjs');

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

function makeSigningProfileDir(baseUrl) {
  const dir = mkdtempSync(join(tmpdir(), 'npcink-cli-contract-'));
  const profilePath = join(dir, 'signing.json');
  const { privateKey } = generateKeyPairSync('ed25519');
  const jwk = privateKey.export({ format: 'jwk' });
  writeFileSync(profilePath, JSON.stringify({
    adapter_base_url: baseUrl,
    key_id: 'mk_cli_contract_test_key',
    private_key_jwk: jwk,
  }), { mode: 0o600 });
  return profilePath;
}

function runRequestWrapper(profilePath) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [
      requestWrapperPath,
      `--profile-file=${profilePath}`,
      'GET',
      '/health',
    ], { stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) {
        return;
      }
      settled = true;
      child.kill('SIGKILL');
      reject(new Error('request wrapper timed out'));
    }, 15000);
    timer.unref();
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.on('error', (error) => {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timer);
      reject(error);
    });
    child.on('close', (code) => {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timer);
      resolve({ code, stdout, stderr });
    });
  });
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
    // Buffer across chunks: a response line split across two stdout
    // chunks would otherwise be dropped and the call would time out.
    let buffer = '';
    const onData = (chunk) => {
      buffer += String(chunk);
      let newlineIndex = buffer.indexOf('\n');
      while (newlineIndex >= 0) {
        const line = buffer.slice(0, newlineIndex).trim();
        buffer = buffer.slice(newlineIndex + 1);
        newlineIndex = buffer.indexOf('\n');
        if (!line) {
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

test('request wrapper failure output keeps the server error data for operators', async () => {
  const server = createServer((req, res) => {
    res.writeHead(403, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      code: 'npcink_openclaw_adapter_signed_request_rejected',
      message: 'The signed request was rejected.',
      data: {
        status: 403,
        reason: 'nonce_replayed',
        operator_feedback: {
          reason: 'nonce_replayed',
          next_step: 'Retry with a fresh nonce from a new request.',
        },
      },
    }));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const profilePath = makeSigningProfileDir(`http://127.0.0.1:${server.address().port}/wp-json/npcink-openclaw-adapter/v1`);
  try {
    const result = await runRequestWrapper(profilePath);
    assert.notEqual(result.code, 0);
    const output = JSON.parse(result.stdout.trim());
    assert.equal(output.ok, false);
    assert.equal(output.status, 403);
    assert.equal(output.code, 'npcink_openclaw_adapter_signed_request_rejected');
    assert.equal(output.data.reason, 'nonce_replayed');
    assert.equal(output.data.operator_feedback.next_step, 'Retry with a fresh nonce from a new request.');
  } finally {
    server.close();
  }
});

test('mcp tool errors carry the operator feedback and redact sensitive error data', async () => {
  const server = createServer((req, res) => {
    res.writeHead(403, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      code: 'npcink_openclaw_adapter_signed_request_scope_denied',
      message: 'The paired client key does not carry the scope required by this route.',
      data: {
        status: 403,
        reason: 'scope_not_granted',
        next_step: 'Re-pair the client requesting the needed scope.',
        operator_feedback: {
          reason: 'scope_not_granted',
          next_step: 'Re-pair the client requesting the needed scope.',
        },
        token: 'super-secret-token-value',
      },
    }));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const profilePath = makeSigningProfileDir(`http://127.0.0.1:${server.address().port}/wp-json/npcink-openclaw-adapter/v1`);
  const child = startMcpServer(profilePath);
  try {
    const response = await rpcCall(child, 9, 'tools/call', { name: 'health', arguments: {} });
    assert.equal(response.result.isError, true);
    const text = response.result.content[0].text;
    assert.equal(text.includes('adapter_request_failed'), true);
    assert.equal(text.includes('Re-pair the client requesting the needed scope.'), true);
    assert.equal(text.includes('super-secret-token-value'), false, 'sensitive error data values must not reach the MCP tool error');
    assert.equal(text.includes('[redacted]'), true, 'sensitive error data keys are replaced with a redaction marker');
  } finally {
    child.kill();
    server.close();
  }
});
