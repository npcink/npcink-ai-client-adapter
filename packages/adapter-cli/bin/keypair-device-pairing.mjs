#!/usr/bin/env node
import { createHash, generateKeyPairSync, randomBytes, sign } from 'node:crypto';
import { spawn } from 'node:child_process';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { mkdirSync, writeFileSync, chmodSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { homedir } from 'node:os';
import { URL } from 'node:url';

const args = new Map();
for (const arg of process.argv.slice(2)) {
  const match = arg.match(/^--([^=]+)=(.*)$/);
  if (match) {
    args.set(match[1], match[2]);
  } else if (arg.startsWith('--')) {
    args.set(arg.slice(2), '1');
  }
}

const site = args.get('site');
const profile = args.get('profile') || 'default';
const clientName = args.get('client') || 'OpenClaw';
const deviceName = args.get('device') || `${process.platform}-${process.arch}`;
const insecureLocalTls = args.has('insecure-local-tls');
const noOpen = args.has('no-open');

if (!site) {
  console.error('Usage: npcink-openclaw-adapter connect --site=https://example.test --profile=example [--insecure-local-tls] [--no-open]');
  process.exit(1);
}

const siteUrl = new URL(site);
const adapterBaseUrl = new URL('/wp-json/npcink-openclaw-adapter/v1', siteUrl).toString().replace(/\/$/, '');
const profilePath = join(homedir(), '.npcink-openclaw-adapter', 'keypair-profiles', `${profile}.json`);

function base64url(buffer) {
  return Buffer.from(buffer).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function isLocalTlsHost(hostname) {
  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1' || hostname.endsWith('.local');
}

function requestJson(method, url, payload = null, headers = {}) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const body = payload === null ? '' : JSON.stringify(payload);
    const transport = target.protocol === 'https:' ? httpsRequest : httpRequest;
    const options = {
      method,
      hostname: target.hostname,
      port: target.port || (target.protocol === 'https:' ? 443 : 80),
      path: `${target.pathname}${target.search}`,
      headers: {
        Accept: 'application/json',
        ...headers,
      },
    };
    if (body) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['Content-Length'] = Buffer.byteLength(body);
    }
    if (target.protocol === 'https:' && insecureLocalTls && isLocalTlsHost(target.hostname)) {
      options.rejectUnauthorized = false;
    }

    const req = transport(options, (res) => {
      let data = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => {
        data += chunk;
      });
      res.on('end', () => {
        let parsed = {};
        try {
          parsed = data ? JSON.parse(data) : {};
        } catch (error) {
          reject(error);
          return;
        }
        if (res.statusCode < 200 || res.statusCode >= 300) {
          const error = new Error(parsed.message || parsed.error || `HTTP ${res.statusCode}`);
          error.statusCode = res.statusCode;
          error.payload = parsed;
          error.method = method;
          error.url = url;
          reject(error);
          return;
        }
        resolve(parsed);
      });
    });
    req.on('error', reject);
    if (body) {
      req.write(body);
    }
    req.end();
  });
}

function isRetryableNetworkError(error) {
  return ['ECONNRESET', 'EPIPE', 'ETIMEDOUT', 'ECONNREFUSED'].includes(error.code) || error.message === 'socket hang up';
}

function openApprovalUrl(url) {
  if (noOpen) {
    return false;
  }
  const command = process.platform === 'darwin' ? 'open' : process.platform === 'win32' ? 'cmd' : 'xdg-open';
  const commandArgs = process.platform === 'win32' ? ['/c', 'start', '', url] : [url];
  try {
    const child = spawn(command, commandArgs, { detached: true, stdio: 'ignore' });
    child.on('error', () => {});
    child.unref();
    return true;
  } catch (error) {
    return false;
  }
}

function canonicalJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map(canonicalJson).join(',')}]`;
  }
  if (value && typeof value === 'object') {
    if (Object.keys(value).length === 0) {
      return '[]';
    }
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

function signedHeaders(privateKey, keyId, method, route, queryParams = {}, body = '') {
  const timestamp = new Date().toISOString();
  const nonce = base64url(randomBytes(18));
  const contentSha256 = `sha256:${createHash('sha256').update(body).digest('hex')}`;
  const canonical = [
    'NPCINK-AI-CLIENT-ADAPTER-V1',
    method.toUpperCase(),
    route,
    canonicalJson(queryParams),
    timestamp,
    nonce,
    contentSha256,
  ].join('\n');
  const signature = sign(null, Buffer.from(canonical), privateKey);
  const signatureText = base64url(signature);
  return {
    Authorization: `Npcink-Signature key_id="${keyId}", timestamp="${timestamp}", nonce="${nonce}", content_sha256="${contentSha256}", alg="Ed25519", signature="${signatureText}"`,
    'X-Npcink-Key-Id': keyId,
    'X-Npcink-Timestamp': timestamp,
    'X-Npcink-Nonce': nonce,
    'X-Npcink-Content-SHA256': contentSha256,
    'X-Npcink-Signature-Alg': 'Ed25519',
    'X-Npcink-Signature': signatureText,
  };
}

const { publicKey, privateKey } = generateKeyPairSync('ed25519');
const publicJwk = publicKey.export({ format: 'jwk' });
const privateJwk = privateKey.export({ format: 'jwk' });

const start = await requestJson('POST', `${adapterBaseUrl}/connect/device/start`, {
  schema_version: 'npcink_openclaw_adapter_device_pairing.v1',
  client: {
    name: clientName,
    device_name: deviceName,
    broker: 'npcink-openclaw-adapter local keypair verifier',
    broker_version: '0.3.0',
  },
  key: {
    alg: 'Ed25519',
    public_key: publicJwk.x,
  },
  requested_scopes: ['npcink.read', 'npcink.propose', 'npcink.status', 'npcink.execute'],
});

const opened = openApprovalUrl(start.verification_uri_complete);
console.log(opened ? 'Opened the WordPress approval URL in your browser.' : 'Open this WordPress approval URL:');
console.log(start.verification_uri_complete);
console.log(`User code: ${start.user_code}`);
console.log('Waiting for approval...');

let paired = null;
const deadline = Date.now() + start.expires_in * 1000;
let transientPollFailures = 0;
while (Date.now() < deadline) {
  await new Promise((resolve) => setTimeout(resolve, (start.interval || 3) * 1000));
  try {
    const poll = await requestJson('POST', `${adapterBaseUrl}/connect/device/poll`, {
      device_code: start.device_code,
    });
    if (poll.ok) {
      paired = poll;
      break;
    }
  } catch (error) {
    if (error.statusCode === 202) {
      continue;
    }
    if (isRetryableNetworkError(error)) {
      transientPollFailures += 1;
      console.log(`Transient polling error (${error.code || error.message}); retrying until the pairing code expires.`);
      continue;
    }
    throw error;
  }
}

if (!paired) {
  throw new Error('Pairing timed out before approval.');
}

const profileData = {
  site_url: siteUrl.origin,
  adapter_base_url: paired.adapter_base_url,
  connection_id: paired.connection_id,
  key_id: paired.key_id,
  scopes_effective: paired.scopes_effective,
  private_key_jwk: privateJwk,
  public_key_jwk: publicJwk,
  created_at: new Date().toISOString(),
};

mkdirSync(dirname(profilePath), { recursive: true, mode: 0o700 });
writeFileSync(profilePath, `${JSON.stringify(profileData, null, 2)}\n`, { mode: 0o600 });
chmodSync(profilePath, 0o600);

const healthUrl = `${adapterBaseUrl}/health`;

console.log(`Connected: ${paired.connection_id}`);
console.log(`Profile saved: ${profilePath}`);
if (transientPollFailures > 0) {
  console.log(`Recovered from transient polling errors: ${transientPollFailures}`);
}
try {
  const health = await requestJson('GET', healthUrl, null, signedHeaders(privateKey, paired.key_id, 'GET', '/npcink-openclaw-adapter/v1/health'));
  console.log(`Health: core_capabilities=${Boolean(health.core_capabilities)} abilities_catalog=${Boolean(health.abilities_catalog)}`);
} catch (error) {
  console.log(`Health check failed after pairing: ${error.statusCode || error.code || error.message}`);
  console.log('The local profile was saved. Re-run after updating the plugin if this was caused by stripped signature headers.');
  process.exitCode = 1;
}
