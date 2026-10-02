#!/usr/bin/env node
import { createHash, createPrivateKey, randomBytes, sign } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { join } from 'node:path';
import { homedir } from 'node:os';
import { URL, URLSearchParams } from 'node:url';

const args = new Map();
const positionals = [];
for (const arg of process.argv.slice(2)) {
  const match = arg.match(/^--([^=]+)=(.*)$/);
  if (match) {
    args.set(match[1], match[2]);
  } else if (arg.startsWith('--')) {
    args.set(arg.slice(2), '1');
  } else {
    positionals.push(arg);
  }
}

const method = (positionals[0] || '').toUpperCase();
const route = positionals[1] || '';
const profile = args.get('profile') || 'default';
const profilePath = args.get('profile-file') || join(homedir(), '.npcink-openclaw-adapter', 'keypair-profiles', `${profile}.json`);
const bodyFile = args.get('body-file') || '';
const bodyStdin = args.has('body-stdin');
const queryJson = args.get('query') || '';
const queryString = args.get('query-string') || '';
const insecureLocalTls = args.has('insecure-local-tls');
const executionIntent = (args.get('intent') || '').toLowerCase();

if (!['GET', 'POST', 'DELETE'].includes(method) || !isSafeAdapterRoute(route)) {
  console.error('Usage: npcink-openclaw-adapter request --profile=local [--insecure-local-tls] METHOD /adapter-route [--intent=preview|preflight|commit] [--query=\'{"key":"value"}\'|--query-string=key=value] [--body-file=/tmp/body.json|--body-stdin]');
  process.exit(2);
}

if (bodyFile && bodyStdin) {
  console.error('Use only one of --body-file or --body-stdin.');
  process.exit(2);
}

function isSafeAdapterRoute(value) {
  return value.startsWith('/') && !value.startsWith('//') && !/^[a-z][a-z0-9+.-]*:/i.test(value);
}

function base64url(buffer) {
  return Buffer.from(buffer).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function isLocalTlsHost(hostname) {
  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1' || hostname.endsWith('.local');
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

function queryParamsObject(searchParams) {
  const out = {};
  for (const [key, value] of searchParams.entries()) {
    if (Object.prototype.hasOwnProperty.call(out, key)) {
      out[key] = Array.isArray(out[key]) ? [...out[key], value] : [out[key], value];
    } else {
      out[key] = value;
    }
  }
  return out;
}

function readProfile(path) {
  const parsed = JSON.parse(readFileSync(path, 'utf8'));
  const adapterBaseUrl = String(parsed.adapter_base_url || '').replace(/\/$/, '');
  const keyId = String(parsed.key_id || '');
  if (!adapterBaseUrl || !keyId || !parsed.private_key_jwk) {
    throw new Error('Profile is missing required connection or signing fields.');
  }
  return {
    adapterBaseUrl,
    keyId,
    privateKey: createPrivateKey({ key: parsed.private_key_jwk, format: 'jwk' }),
  };
}

function routeSearchParams() {
  const url = new URL(route, 'https://adapter.invalid');
  const params = new URLSearchParams(url.search);
  if (queryString) {
    const extra = new URLSearchParams(queryString);
    for (const [key, value] of extra.entries()) {
      params.append(key, value);
    }
  }
  if (queryJson) {
    const parsed = JSON.parse(queryJson);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error('--query must be a JSON object.');
    }
    for (const key of Object.keys(parsed)) {
      const value = parsed[key];
      if (Array.isArray(value)) {
        for (const item of value) {
          params.append(key, String(item));
        }
      } else if (value !== null && value !== undefined) {
        params.set(key, String(value));
      }
    }
  }
  return params;
}

function isFinalWriteRoute(methodValue, cleanRoute) {
  if (methodValue !== 'POST') {
    return false;
  }
  return cleanRoute === '/execute-approved-proposal'
    || /^\/proposals\/[A-Za-z0-9_-]+\/execute$/.test(cleanRoute)
    || /^\/proposals\/[A-Za-z0-9_-]+\/approve-and-execute$/.test(cleanRoute);
}

function containsPreviewOnlyMarker(value) {
  if (Array.isArray(value)) {
    return value.some((item) => containsPreviewOnlyMarker(item));
  }
  if (!value || typeof value !== 'object') {
    return false;
  }
  if (value.dry_run === true || value.commit === false || value.commit_execution === false) {
    return true;
  }
  return Object.values(value).some((item) => containsPreviewOnlyMarker(item));
}

function enforceExecutionIntent(methodValue, cleanRoute, bodyPayload) {
  if (!isFinalWriteRoute(methodValue, cleanRoute)) {
    return;
  }
  if (containsPreviewOnlyMarker(bodyPayload)) {
    throw new Error('Refusing final Adapter execute route because the request body contains dry-run, commit=false, or commit_execution=false preview markers. Stop at commit-preflight for dry-run/preflight-only verification.');
  }
  if (executionIntent !== 'commit') {
    throw new Error('Refusing final Adapter execute route without --intent=commit. Use --intent=preflight with /proposals/{proposal_id}/commit-preflight for dry-run/preflight-only verification.');
  }
}

function signedHeaders(privateKey, keyId, methodValue, restRoute, queryParams, body) {
  const timestamp = new Date().toISOString();
  const nonce = base64url(randomBytes(18));
  const contentSha256 = `sha256:${createHash('sha256').update(body).digest('hex')}`;
  const canonical = [
    'NPCINK-AI-CLIENT-ADAPTER-V1',
    methodValue,
    restRoute,
    canonicalJson(queryParams),
    timestamp,
    nonce,
    contentSha256,
  ].join('\n');
  const signatureText = base64url(sign(null, Buffer.from(canonical), privateKey));
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

function requestJson(methodValue, url, body, headers) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const transport = target.protocol === 'https:' ? httpsRequest : httpRequest;
    const options = {
      method: methodValue,
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
        let parsed = null;
        try {
          parsed = data ? JSON.parse(data) : null;
        } catch (error) {
          reject(new Error(`Adapter returned non-JSON response with HTTP ${res.statusCode}`));
          return;
        }
        if (res.statusCode < 200 || res.statusCode >= 300) {
          const output = {
            ok: false,
            status: res.statusCode,
            code: parsed && typeof parsed === 'object' ? parsed.code || '' : '',
            message: parsed && typeof parsed === 'object' ? parsed.message || '' : '',
          };
          // Adapter error data carries reason, next_step, retry_after, and
          // route-specific operator_feedback; operators need it.
          const errorData = parsed && typeof parsed === 'object' ? parsed.data : null;
          if (errorData && typeof errorData === 'object' && !Array.isArray(errorData) && Object.keys(errorData).length > 0) {
            output.data = redactOutput(errorData);
          }
          console.log(JSON.stringify(output, null, 2));
          process.exitCode = 1;
          resolve(null);
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

function redactOutput(value) {
  if (Array.isArray(value)) {
    return value.map((item) => redactOutput(item));
  }
  if (!value || typeof value !== 'object') {
    if (
      typeof value === 'string'
      && (
        value.includes('.npcink-openclaw-adapter/keypair-profiles/')
        || /authorization\s*:/i.test(value)
        || /x-npcink-/i.test(value)
        || /signature\s*=/i.test(value)
      )
    ) {
      return '[redacted]';
    }
    return value;
  }
  const out = {};
  for (const [key, item] of Object.entries(value)) {
    out[key] = isSensitiveOutputKey(key) ? '[redacted]' : redactOutput(item);
  }
  return out;
}

function isSensitiveOutputKey(key) {
  return [
    'profile_path',
    'profile_json',
    'private_key',
    'private_key_jwk',
    'public_key',
    'key_id',
    'connection_id',
    'authorization',
    'cookie',
    'token',
    'application_password',
    'password',
    'secret',
    'signature',
    'x_npcink_key_id',
    'x_npcink_signature',
  ].includes(String(key).toLowerCase().replace(/-/g, '_'));
}

try {
  const routeUrl = new URL(route, 'https://adapter.invalid');
  const cleanRoute = routeUrl.pathname;
  const params = routeSearchParams();
  const queryStringOut = params.toString();
  const body = bodyFile ? readFileSync(bodyFile, 'utf8') : bodyStdin ? readFileSync(0, 'utf8') : '';
  let bodyPayload = null;
  if (body) {
    bodyPayload = JSON.parse(body);
  }
  enforceExecutionIntent(method, cleanRoute, bodyPayload);
  const profileData = readProfile(profilePath);
  const adapterUrl = `${profileData.adapterBaseUrl}${cleanRoute}${queryStringOut ? `?${queryStringOut}` : ''}`;
  const restRoute = new URL(adapterUrl).pathname.replace(/^\/wp-json/, '');
  const queryForSignature = queryParamsObject(params);
  const headers = signedHeaders(profileData.privateKey, profileData.keyId, method, restRoute, queryForSignature, body);
  const response = await requestJson(method, adapterUrl, body, headers);
  if (response !== null) {
    console.log(JSON.stringify(redactOutput(response), null, 2));
  }
} catch (error) {
  console.error(JSON.stringify({
    ok: false,
    error: 'wrapper_failed',
    message: error.message,
  }, null, 2));
  process.exit(1);
}
