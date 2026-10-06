#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const toolDir = dirname(fileURLToPath(import.meta.url));
const rawArgs = process.argv.slice(2);
const command = rawArgs[0] || '';
const commandArgs = rawArgs.slice(1);
const AI_IMAGE_RATIO_CROP_RECIPE_ID = 'ai_image_ratio_crop_media_adoption';
const AI_IMAGE_RATIO_CROP_RECIPE_CLI_ID = 'ai-image-ratio-crop-media-adoption';
// Adapter /help no longer carries recipe playbooks (thin-channel cleanup).
// The CLI mirrors the reviewed recipe contract locally; the contract source
// of truth is docs/recipes/openclaw-ai-image-ratio-crop-media-adoption-recipe.md.
const AI_IMAGE_RATIO_CROP_RECIPE_CONTRACT = {
  recipe_id: AI_IMAGE_RATIO_CROP_RECIPE_ID,
  title: 'AI image ratio crop media adoption',
  plan_ability_id: 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
  default_input: {
    preferred_format: 'webp',
    quality: 84,
  },
  guardrails: {
    target_aspect_ratio_required: true,
    ai_generation_dimensions_are_advisory: true,
    cloud_crop_required_for_generated_images: true,
    direct_wordpress_write: false,
    adapter_artifact_registry: false,
  },
  contract_source: 'cli-local-mirror',
  contract_docs: 'docs/recipes/openclaw-ai-image-ratio-crop-media-adoption-recipe.md',
};

if (!['connect', 'status', 'request', 'read-request', 'read-ability', 'recipe', 'mcp'].includes(command)) {
  printUsage();
  process.exit(2);
}

function printUsage() {
  console.error([
    'Usage:',
    '  npcink-openclaw-adapter connect --site=https://example.test --profile=local [--insecure-local-tls]',
    '  npcink-openclaw-adapter status --profile=local [--insecure-local-tls]',
    '  npcink-openclaw-adapter request --profile=local [--insecure-local-tls] METHOD /adapter-route [--body-file=/tmp/body.json|--body-stdin]',
    '  npcink-openclaw-adapter read-request create --profile=local --ability-id=ABILITY_ID --input-file=/tmp/input.json --purpose=PURPOSE --data-classes=CLASS[,CLASS]',
    '  npcink-openclaw-adapter read-request status --profile=local REQUEST_ID',
    '  npcink-openclaw-adapter read-ability --profile=local --ability-id=ABILITY_ID --input-file=/tmp/input.json [--read-request-id=REQUEST_ID]',
    '  npcink-openclaw-adapter recipe ai-image-ratio-crop-media-adoption inspect --profile=local',
    '  npcink-openclaw-adapter recipe ai-image-ratio-crop-media-adoption adoption-plan --profile=local --preview-url=URL --post-id=123 [--old-url=URL] [--source-type=ai_generated] [--submit-proposal]',
    '  npcink-openclaw-adapter mcp --profile=local [--insecure-local-tls]  (stdio MCP server; governed read/propose/execute tools; execution only for proposals a human approved in the Core admin)',
  ].join('\n'));
}

function parseArgs(args) {
  const parsed = new Map();
  const positionals = [];
  for (const arg of args) {
    const match = arg.match(/^--([^=]+)=(.*)$/);
    if (match) {
      parsed.set(match[1], match[2]);
    } else if (arg.startsWith('--')) {
      parsed.set(arg.slice(2), '1');
    } else {
      positionals.push(arg);
    }
  }
  return { parsed, positionals };
}

function profilePathFromArgs(args) {
  const { parsed } = parseArgs(args);
  const profile = parsed.get('profile') || 'default';
  return {
    profile,
    profilePath: parsed.get('profile-file') || join(homedir(), '.npcink-openclaw-adapter', 'keypair-profiles', `${profile}.json`),
    insecureLocalTls: parsed.has('insecure-local-tls'),
  };
}

function runNode(scriptName, args, options = {}) {
  return new Promise((resolve, reject) => {
    const childStdio = ['pipe', 'pipe', 'pipe'];
    if (options.input === undefined) {
      childStdio[0] = options.ignoreStdin ? 'ignore' : 'inherit';
    }
    if (!options.capture) {
      childStdio[1] = 'inherit';
      childStdio[2] = 'inherit';
    }
    const child = spawn(process.execPath, [join(toolDir, scriptName), ...args], { stdio: childStdio });
    let stdout = '';
    let stderr = '';
    let timeout = null;
    if (options.timeoutMs !== undefined) {
      // A hung child must not stall the caller (the serialized MCP dispatch
      // chain); kill it and let the close event resolve the promise.
      timeout = setTimeout(() => {
        stderr += `\n[timeout after ${options.timeoutMs}ms]`;
        child.kill('SIGKILL');
      }, options.timeoutMs);
      timeout.unref();
    }
    if (options.capture) {
      child.stdout.on('data', (chunk) => {
        stdout += chunk;
      });
      child.stderr.on('data', (chunk) => {
        stderr += chunk;
      });
    }
    child.on('error', (error) => {
      if (timeout) {
        clearTimeout(timeout);
      }
      reject(error);
    });
    if (options.input !== undefined) {
      // A dead child surfaces as EPIPE here; the close event below still
      // resolves with the exit code, so the stdin error is safe to ignore.
      child.stdin.on('error', () => {});
      child.stdin.write(options.input);
      child.stdin.end();
    }
    child.on('close', (code) => {
      if (timeout) {
        clearTimeout(timeout);
      }
      resolve({ code, stdout, stderr });
    });
  });
}

async function connect(args) {
  const result = await runNode('keypair-device-pairing.mjs', args);
  process.exitCode = result.code || 0;
}

async function request(args) {
  const result = await runNode('keypair-adapter-request.mjs', args, { capture: true });
  printCapturedResult(result);
  process.exitCode = result.code || 0;
}

async function readRequest(args) {
  const subcommand = args[0] || '';
  const subArgs = args.slice(1);
  if ('create' === subcommand) {
    await readRequestCreate(subArgs);
    return;
  }
  if ('status' === subcommand) {
    await readRequestStatus(subArgs);
    return;
  }
  printUsage();
  process.exitCode = 2;
}

async function readRequestCreate(args) {
  const { parsed } = parseArgs(args);
  const abilityId = parsed.get('ability-id') || '';
  const purpose = parsed.get('purpose') || '';
  const dataClasses = csvList(parsed.get('data-classes') || '');
  if (!abilityId || !purpose || dataClasses.length === 0) {
    console.error(JSON.stringify({
      ok: false,
      error: 'usage',
      message: 'read-request create requires --ability-id, --purpose, and --data-classes.',
    }, null, 2));
    process.exitCode = 2;
    return;
  }

  const body = {
    ability_id: abilityId,
    input: inputPayloadFromArgs(parsed),
    requested_input_summary: parsed.get('requested-input-summary') || '',
    data_classes: dataClasses,
    purpose,
    redaction_level: parsed.get('redaction-level') || 'strict',
  };
  const bounds = boundsFromArgs(parsed);
  if (Object.keys(bounds).length > 0) {
    body.bounds = bounds;
  }

  const result = await runNode('keypair-adapter-request.mjs', [
    ...requestCommonArgs(parsed),
    'POST',
    '/read-requests',
    '--body-stdin',
  ], { capture: true, input: JSON.stringify(body) });
  printCapturedResult(result);
  process.exitCode = result.code || 0;
}

async function readRequestStatus(args) {
  const { parsed, positionals } = parseArgs(args);
  const requestId = positionals[0] || '';
  if (!/^[A-Za-z0-9_-]+$/.test(requestId)) {
    console.error(JSON.stringify({
      ok: false,
      error: 'usage',
      message: 'read-request status requires a safe request id.',
    }, null, 2));
    process.exitCode = 2;
    return;
  }

  const result = await runNode('keypair-adapter-request.mjs', [
    ...requestCommonArgs(parsed),
    'GET',
    `/read-requests/${requestId}`,
  ], { capture: true });
  printCapturedResult(result);
  process.exitCode = result.code || 0;
}

async function readAbility(args) {
  const { parsed } = parseArgs(args);
  const abilityId = parsed.get('ability-id') || '';
  if (!abilityId) {
    console.error(JSON.stringify({
      ok: false,
      error: 'usage',
      message: 'read-ability requires --ability-id.',
    }, null, 2));
    process.exitCode = 2;
    return;
  }

  const body = {
    ability_id: abilityId,
    input: inputPayloadFromArgs(parsed),
  };
  if (parsed.get('read-request-id')) {
    body.read_request_id = parsed.get('read-request-id');
  }

  const result = await runNode('keypair-adapter-request.mjs', [
    ...requestCommonArgs(parsed),
    'POST',
    '/run-read-ability',
    '--body-stdin',
  ], { capture: true, input: JSON.stringify(body) });
  printCapturedResult(result);
  process.exitCode = result.code || 0;
}

async function recipe(args) {
  const recipeName = args[0] || '';
  const action = args[1] || '';
  const subArgs = args.slice(2);
  if (![AI_IMAGE_RATIO_CROP_RECIPE_ID, AI_IMAGE_RATIO_CROP_RECIPE_CLI_ID].includes(recipeName)) {
    printUsage();
    process.exitCode = 2;
    return;
  }
  if (!['inspect', 'adoption-plan'].includes(action)) {
    printUsage();
    process.exitCode = 2;
    return;
  }

  const { parsed } = parseArgs(subArgs);
  const recipeContract = aiImageRatioCropRecipeContract();
  if (action === 'inspect') {
    console.log(JSON.stringify({
      ok: true,
      recipe_id: AI_IMAGE_RATIO_CROP_RECIPE_ID,
      cli_recipe_id: AI_IMAGE_RATIO_CROP_RECIPE_CLI_ID,
      recipe: recipeContract,
      supported_actions: ['adoption-plan'],
      note: 'Cloud crop and result transport belongs to Cloud Addon or Cloud tooling. This helper accepts a reviewed preview URL and can submit a Core proposal plan when explicitly requested. Adapter /help no longer exposes openclaw_recipes; this contract is a local mirror of the reviewed recipe document.',
    }, null, 2));
    return;
  }
  await recipeAiImageAdoptionPlan(parsed, recipeContract);
}

function aiImageRatioCropRecipeContract() {
  const contract = AI_IMAGE_RATIO_CROP_RECIPE_CONTRACT;
  if (!contract || typeof contract !== 'object'
    || !contract.plan_ability_id
    || !contract.guardrails
    || contract.guardrails.target_aspect_ratio_required !== true
    || contract.guardrails.ai_generation_dimensions_are_advisory !== true
    || contract.guardrails.cloud_crop_required_for_generated_images !== true
    || contract.guardrails.direct_wordpress_write !== false
    || contract.guardrails.adapter_artifact_registry !== false) {
    throw new Error('Local AI image crop adoption recipe contract does not match the expected review boundary.');
  }
  return contract;
}

async function recipeAiImageAdoptionPlan(parsed, recipeContract) {
  const previewUrl = parsed.get('preview-url') || parsed.get('url') || '';
  if (!previewUrl) {
    throw new Error('recipe adoption-plan requires --preview-url or --url from a reviewed Cloud Addon or Cloud media derivative result.');
  }

  const defaultInput = recipeContract.default_input || {};
  const input = {
    ...inputPayloadFromArgs(parsed),
    url: previewUrl,
    preferred_format: parsed.get('preferred-format') || defaultInput.preferred_format || 'webp',
    quality: positiveInt(parsed.get('quality') || defaultInput.quality || '84'),
  };
  copyParsedValue(parsed, input, 'old-url', 'old_url');
  copyParsedInt(parsed, input, 'post-id', 'post_id');
  copyParsedInt(parsed, input, 'attach-to-post-id', 'attach_to_post_id');
  copyParsedValue(parsed, input, 'title', 'title');
  copyParsedValue(parsed, input, 'alt-text', 'alt');
  copyParsedValue(parsed, input, 'caption', 'caption');
  copyParsedValue(parsed, input, 'description', 'description');
  copyParsedValue(parsed, input, 'file-name', 'file_name');
  copyParsedValue(parsed, input, 'source-type', 'source_type');
  copyParsedValue(parsed, input, 'source-page-url', 'source_page_url');
  copyParsedValue(parsed, input, 'photographer-name', 'photographer_name');
  copyParsedValue(parsed, input, 'attribution-text', 'attribution_text');
  copyParsedValue(parsed, input, 'copyright-notice', 'copyright_notice');

  const planAbilityId = String(recipeContract.plan_ability_id || 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan');
  const planResponse = await requestJsonViaWrapper(parsed, 'POST', '/run-read-ability', {
    ability_id: planAbilityId,
    input,
  });

  if (!parsed.has('submit-proposal')) {
    console.log(JSON.stringify({
      ok: true,
      recipe_id: AI_IMAGE_RATIO_CROP_RECIPE_ID,
      action: 'adoption-plan',
      plan_ability_id: planAbilityId,
      plan_response: planResponse,
      next_step: 'Review the plan_response.result, then submit it to /proposals/from-plan when ready.',
    }, null, 2));
    return;
  }

  const plan = planFromReadAbilityResponse(planResponse);
  const fromPlanBody = {
    plan_ability_id: planAbilityId,
    plan,
    plan_input: input,
    caller: {
      external_thread_id: parsed.get('external-thread-id') || 'openclaw-ai-image-ratio-crop-media-adoption',
      recipe_id: AI_IMAGE_RATIO_CROP_RECIPE_ID,
      via: 'npcink-openclaw-adapter-cli',
    },
  };
  const proposalResponse = await requestJsonViaWrapper(parsed, 'POST', '/proposals/from-plan', fromPlanBody);
  console.log(JSON.stringify({
    ok: true,
    recipe_id: AI_IMAGE_RATIO_CROP_RECIPE_ID,
    action: 'adoption-plan',
    submitted_proposal: true,
    plan_ability_id: planAbilityId,
    plan_response: planResponse,
    proposal_response: proposalResponse,
    next_step: 'Poll the proposal and approve/execute only through the Core/Adapter approved proposal flow after operator review.',
  }, null, 2));
}

async function requestJsonViaWrapper(parsed, method, route, body = null, options = {}) {
  const args = [
    ...requestCommonArgs(parsed),
    method,
    route,
  ];
  // Never let the wrapper inherit our stdin: in the long-lived MCP server it
  // would consume the pending JSON-RPC stream.
  const runOptions = { capture: true, ignoreStdin: body === null, ...options };
  if (body !== null) {
    args.push('--body-stdin');
    runOptions.input = JSON.stringify(body);
  }
  if (options.intent) {
    // The wrapper refuses final-write routes without an explicit intent.
    args.push(`--intent=${options.intent}`);
  }
  const result = await runNode('keypair-adapter-request.mjs', args, runOptions);
  if (result.code !== 0) {
    throw new Error(safeErrorMessage(result.stdout, result.stderr));
  }
  try {
    return JSON.parse(result.stdout);
  } catch (error) {
    throw new Error(`Adapter wrapper returned non-JSON output for ${method} ${route}.`);
  }
}

function planFromReadAbilityResponse(response) {
  const result = response && typeof response === 'object' ? response.result : null;
  if (!result || typeof result !== 'object' || Array.isArray(result)) {
    throw new Error('Read ability response did not include an object result plan.');
  }
  if (result.data && typeof result.data === 'object' && !Array.isArray(result.data)) {
    return result.data;
  }
  return result;
}

function copyParsedValue(parsed, target, argName, key) {
  if (parsed.get(argName)) {
    target[key] = parsed.get(argName);
  }
}

function copyParsedInt(parsed, target, argName, key) {
  if (parsed.get(argName)) {
    target[key] = positiveInt(parsed.get(argName));
  }
}

async function status(args) {
  const { profile, profilePath, insecureLocalTls } = profilePathFromArgs(args);
  if (!existsSync(profilePath)) {
    console.log(JSON.stringify({
      ok: false,
      status: 'missing_profile',
      profile,
      profile_configured: false,
      message: 'Run connect before status.',
    }, null, 2));
    process.exitCode = 1;
    return;
  }

  let metadata = {};
  try {
    const profileData = JSON.parse(readFileSync(profilePath, 'utf8'));
    metadata = {
      adapter_base_url: String(profileData.adapter_base_url || ''),
      created_at: String(profileData.created_at || ''),
      scopes_effective: Array.isArray(profileData.scopes_effective) ? profileData.scopes_effective : [],
    };
  } catch (error) {
    console.log(JSON.stringify({
      ok: false,
      status: 'invalid_profile',
      profile,
      profile_configured: true,
      message: error.message,
    }, null, 2));
    process.exitCode = 1;
    return;
  }

  const requestArgs = [`--profile=${profile}`];
  if (args.some((arg) => arg.startsWith('--profile-file='))) {
    requestArgs.push(`--profile-file=${profilePath}`);
  }
  if (insecureLocalTls) {
    requestArgs.push('--insecure-local-tls');
  }
  requestArgs.push('GET', '/health');

  const result = await runNode('keypair-adapter-request.mjs', requestArgs, { capture: true });
  if (result.code !== 0) {
    console.log(JSON.stringify({
      ok: false,
      status: 'health_failed',
      profile,
      profile_configured: true,
      connection: metadata,
      message: safeErrorMessage(result.stdout, result.stderr),
    }, null, 2));
    process.exitCode = result.code || 1;
    return;
  }

  let health;
  try {
    health = JSON.parse(result.stdout);
  } catch {
    console.log(JSON.stringify({
      ok: false,
      status: 'health_unparseable',
      profile,
      profile_configured: true,
      connection: metadata,
      message: 'Adapter /health returned output that is not valid JSON. Check whether a proxy or error page intercepted the request.',
    }, null, 2));
    process.exitCode = 1;
    return;
  }
  const coreProxyExecute = Boolean(health.core_proxy_execute);
  const commitExecution = Boolean(health.commit_execution);
  const boundaryOk = !coreProxyExecute && !commitExecution;
  const policyBoundary = health.client_policy?.boundary_enforcement ?? null;
  const boundaryClass = policyBoundary && policyBoundary.class ? String(policyBoundary.class) : '';
  const approvedProposalExecutionRoutes = Array.isArray(health.approved_proposal_execution_routes) ? health.approved_proposal_execution_routes : [];
  const supportedExecuteAbilityIds = Array.isArray(health.supported_execute_ability_ids) ? health.supported_execute_ability_ids : [];
  let proposalExecutionStatus = 'unknown_check_health';
  if (!health.core_capabilities || !health.abilities_catalog) {
    proposalExecutionStatus = 'blocked_by_missing_dependencies';
  } else if (approvedProposalExecutionRoutes.length > 0) {
    proposalExecutionStatus = 'available_via_adapter_routes';
  }

  console.log(JSON.stringify({
    ok: true,
    status: 'ready',
    profile,
    profile_configured: true,
    connection: metadata,
    health: {
      core_capabilities: Boolean(health.core_capabilities),
      abilities_catalog: Boolean(health.abilities_catalog),
      core_proxy_execute: coreProxyExecute,
      commit_execution: commitExecution,
    },
    boundary: {
      status: boundaryOk ? 'ok' : 'unexpected',
      expected: {
        core_proxy_execute: false,
        commit_execution: false,
      },
      note: 'false values indicate Core keeps final execution authority separate from Adapter diagnostics.',
    },
    boundary_enforcement: boundaryClass
      ? {
          class: boundaryClass,
          auth_mode: String(policyBoundary.auth_mode || ''),
          recommended: String(policyBoundary.recommended || ''),
          note: 'enforced: Adapter routes are the only reachable WordPress path for this credential; conventional: the credential can also reach wp/v2 directly.',
        }
      : {
          class: 'unknown',
          auth_mode: 'unknown',
          recommended: '',
          note: `Adapter did not report client_policy.boundary_enforcement (reported client_policy.policy_version: ${String(health.client_policy?.policy_version || 'unknown')}); update the Adapter plugin.`,
        },
    proposal_execution: {
      status: proposalExecutionStatus,
      routes: approvedProposalExecutionRoutes,
      supported_ability_ids: supportedExecuteAbilityIds,
      readiness_rule: 'Use GET /proposals/{proposal_id}; execute only through Adapter approve-and-execute or execute routes after Core approval and commit-preflight.',
    },
  }, null, 2));
}

const MCP_DEFAULT_PROTOCOL_VERSION = '2025-06-18';
const MCP_SAFE_ID_PATTERN = /^[A-Za-z0-9_-]{1,190}$/;
const MCP_SAFE_ID_SCHEMA_PATTERN = '^[A-Za-z0-9_-]{1,190}$';
const MCP_MAX_LINE_BYTES = 4 * 1024 * 1024;
const MCP_TOOL_CALL_TIMEOUT_MS = 120000;
const MCP_FINAL_WRITE_TIMEOUT_MS = 600000;

function mcpToolDescriptors() {
  return [
    {
      name: 'health',
      description: 'Adapter health, dependency readiness, and boundary posture. Expected boundary controls: approval_proxy_enabled=false, core_proxy_execute=false, commit_execution=false.',
      inputSchema: { type: 'object', properties: {}, additionalProperties: false },
      route: () => ({ method: 'GET', path: '/health' }),
    },
    {
      name: 'capabilities',
      description: 'Npcink Governance Core capability guidance per ability id. Treat this as the only governance truth when choosing abilities and governance modes.',
      inputSchema: { type: 'object', properties: {}, additionalProperties: false },
      route: () => ({ method: 'GET', path: '/capabilities' }),
    },
    {
      name: 'list_proposals',
      description: 'List recent Core governance proposals, newest first.',
      inputSchema: {
        type: 'object',
        properties: { limit: { type: 'integer', minimum: 1, maximum: 100, description: 'Maximum proposals to return (default 20).' } },
        additionalProperties: false,
      },
      route: (input) => {
        const limit = Math.max(1, Math.min(100, Number.parseInt(String(input.limit ?? '20'), 10) || 20));
        return { method: 'GET', path: `/proposals?limit=${limit}` };
      },
    },
    {
      name: 'proposal_status',
      description: 'Read one Core governance proposal with its approval status, preview, and audit timeline.',
      inputSchema: {
        type: 'object',
        properties: { proposal_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN } },
        required: ['proposal_id'],
        additionalProperties: false,
      },
      idFields: ['proposal_id'],
      route: (input) => ({ method: 'GET', path: `/proposals/${encodeURIComponent(String(input.proposal_id))}` }),
    },
    {
      name: 'run_read_ability',
      description: 'Run one approved direct-read ability through WordPress Abilities API. Sensitive reads fail closed with npcink_openclaw_adapter_core_read_authorization_required; then create a read request, wait for Core approval, and call again with the same ability_id, same input, and the approved read_request_id.',
      inputSchema: {
        type: 'object',
        properties: {
          ability_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN },
          input: { type: 'object', description: 'Ability input as documented in /capabilities.' },
          read_request_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN, description: 'Approved Core read request id for sensitive reads.' },
          log_context: { type: 'object', description: 'Optional bounded correlation fields such as correlation_id or external_thread_id.' },
        },
        required: ['ability_id', 'input'],
        additionalProperties: false,
      },
      idFields: ['read_request_id'],
      route: (input) => ({
        method: 'POST',
        path: '/run-read-ability',
        body: {
          ability_id: String(input.ability_id),
          input: input.input,
          ...(input.read_request_id ? { read_request_id: String(input.read_request_id) } : {}),
          ...(input.log_context ? { log_context: input.log_context } : {}),
        },
      }),
    },
    {
      name: 'read_request_create',
      description: 'Create a Core read request to authorize a sensitive read. Wait for approval, then call run_read_ability with the granted read_request_id.',
      inputSchema: {
        type: 'object',
        properties: {
          ability_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN },
          input: { type: 'object' },
          purpose: { type: 'string', description: 'Operator-facing purpose for the sensitive read.' },
          data_classes: { type: 'array', items: { type: 'string' }, description: 'Data classes such as diagnostics or logs.' },
          redaction_level: { type: 'string', default: 'strict' },
          requested_input_summary: { type: 'string' },
        },
        required: ['ability_id', 'input', 'purpose', 'data_classes'],
        additionalProperties: false,
      },
      route: (input) => ({
        method: 'POST',
        path: '/read-requests',
        body: {
          ability_id: String(input.ability_id),
          input: input.input,
          purpose: String(input.purpose),
          data_classes: Array.isArray(input.data_classes) ? input.data_classes.map((value) => String(value)) : [],
          redaction_level: String(input.redaction_level || 'strict'),
          ...(input.requested_input_summary ? { requested_input_summary: String(input.requested_input_summary) } : {}),
        },
      }),
    },
    {
      name: 'read_request_status',
      description: 'Check whether a Core read request has been approved, rejected, or is still pending.',
      inputSchema: {
        type: 'object',
        properties: { request_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN } },
        required: ['request_id'],
        additionalProperties: false,
      },
      idFields: ['request_id'],
      route: (input) => ({ method: 'GET', path: `/read-requests/${encodeURIComponent(String(input.request_id))}` }),
    },
    {
      name: 'propose_write',
      description: 'Create one Core governance proposal for a write-class ability. This only submits the request for human approval; a WordPress administrator must approve it in the Core admin. Execution is a separate explicit step: run commit_preflight for verification or execute_approved for the final write, each with the required intent. Rejected or blocked proposals should be shown to the operator, not retried blindly.',
      inputSchema: {
        type: 'object',
        properties: {
          ability_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN },
          input: { type: 'object' },
          preview: { type: 'object', description: 'Optional preview evidence for the reviewer.' },
          title: { type: 'string' },
          summary: { type: 'string' },
        },
        required: ['ability_id', 'input'],
        additionalProperties: false,
      },
      route: (input) => ({
        method: 'POST',
        path: '/proposals',
        body: {
          ability_id: String(input.ability_id),
          input: input.input,
          ...(input.preview ? { preview: input.preview } : {}),
          ...(input.title ? { title: String(input.title) } : {}),
          ...(input.summary ? { summary: String(input.summary) } : {}),
          caller: { via: 'npcink-openclaw-adapter-cli-mcp' },
        },
      }),
    },
    proposalIntentTool(
      'commit_preflight',
      'Run the Core commit preflight for one proposal and return its evidence. Diagnostic only: nothing is executed. Use it to verify an approved proposal before final execution; stop here for dry-run-only checks.',
      'commit-preflight',
      'preflight'
    ),
    proposalIntentTool(
      'execute_approved',
      'Execute one proposal that a human has already approved in the Core admin. This is a final write: the ability runs with dry_run=false and commit=true. Only proceed when the operator explicitly asked to execute the approved proposal.',
      'execute',
      'commit'
    ),
  ];
}

function proposalIntentTool(name, description, suffix, intent) {
  return {
    name,
    description,
    inputSchema: {
      type: 'object',
      properties: {
        proposal_id: { type: 'string', pattern: MCP_SAFE_ID_SCHEMA_PATTERN },
        intent: { type: 'string', enum: [intent] },
      },
      required: ['proposal_id', 'intent'],
      additionalProperties: false,
    },
    idFields: ['proposal_id'],
    requiredIntent: intent,
    route: (input) => ({ method: 'POST', path: `/proposals/${encodeURIComponent(String(input.proposal_id))}/${suffix}`, intent }),
  };
}

function mcpToolResult(payload, isError = false) {
  return {
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    ...(isError ? { isError: true } : {}),
  };
}

async function mcpCallTool(parsed, tools, params) {
  const name = String((params && params.name) || '');
  const tool = tools.find((candidate) => candidate.name === name);
  if (!tool) {
    return mcpToolResult({ ok: false, error: 'unknown_tool', message: `Unknown MCP tool: ${name || '(empty)'}. This surface exposes read, propose, and explicitly-intended execution tools only.`, available_tools: tools.map((candidate) => candidate.name) }, true);
  }

  const input = params && typeof params.arguments === 'object' && params.arguments !== null && !Array.isArray(params.arguments) ? params.arguments : {};

  // MCP input schemas are advisory to clients, so enforce them here and fail
  // closed with an isError result instead of forwarding malformed requests.
  const required = Array.isArray(tool.inputSchema.required) ? tool.inputSchema.required : [];
  const missing = required.filter((key) => input[key] === undefined || input[key] === null || input[key] === '' || (Array.isArray(input[key]) && input[key].length === 0));
  if (missing.length > 0) {
    return mcpToolResult({ ok: false, error: 'invalid_params', message: `Missing required argument(s): ${missing.join(', ')}.` }, true);
  }
  const declaredProperties = tool.inputSchema.properties && typeof tool.inputSchema.properties === 'object' ? tool.inputSchema.properties : {};
  for (const [key, schema] of Object.entries(declaredProperties)) {
    const value = input[key];
    if (value === undefined || value === null || value === '') {
      continue;
    }
    const expectedType = schema && typeof schema === 'object' ? schema.type : '';
    if (expectedType === 'object' && (typeof value !== 'object' || Array.isArray(value))) {
      return mcpToolResult({ ok: false, error: 'invalid_params', message: `Argument ${key} must be an object.` }, true);
    }
    if (expectedType === 'array' && !Array.isArray(value)) {
      return mcpToolResult({ ok: false, error: 'invalid_params', message: `Argument ${key} must be an array.` }, true);
    }
    if (expectedType === 'string' && typeof value !== 'string') {
      return mcpToolResult({ ok: false, error: 'invalid_params', message: `Argument ${key} must be a string.` }, true);
    }
    if (expectedType === 'integer' && (typeof value !== 'number' || !Number.isInteger(value))) {
      return mcpToolResult({ ok: false, error: 'invalid_params', message: `Argument ${key} must be an integer.` }, true);
    }
    if (expectedType === 'number' && typeof value !== 'number') {
      return mcpToolResult({ ok: false, error: 'invalid_params', message: `Argument ${key} must be a number.` }, true);
    }
  }
  const idFields = Array.isArray(tool.idFields) ? tool.idFields : [];
  for (const key of idFields) {
    const value = input[key];
    if (value !== undefined && value !== null && value !== '' && !MCP_SAFE_ID_PATTERN.test(String(value))) {
      return mcpToolResult({ ok: false, error: 'invalid_params', message: `Argument ${key} must match ${MCP_SAFE_ID_SCHEMA_PATTERN}.` }, true);
    }
  }
  if (tool.requiredIntent && String(input.intent || '') !== tool.requiredIntent) {
    // Mirrors the CLI --intent discipline: final writes and preflights need an
    // explicit operator-facing intent, never an accidental default.
    return mcpToolResult({ ok: false, error: 'invalid_intent', message: `This tool requires intent="${tool.requiredIntent}". Confirm the operator explicitly requested this action.` }, true);
  }

  const request = tool.route(input);

  try {
    const response = await requestJsonViaWrapper(parsed, request.method, request.path, 'body' in request ? request.body : null, {
      timeoutMs: request.intent ? MCP_FINAL_WRITE_TIMEOUT_MS : MCP_TOOL_CALL_TIMEOUT_MS,
      ...(request.intent ? { intent: request.intent } : {}),
    });
    return mcpToolResult(response);
  } catch (error) {
    const message = request.intent && /timeout/i.test(String(error.message || ''))
      ? `${error.message} A timeout does not mean the write failed; check proposal_status before retrying, because the server-side duplicate-execution guard may report the write as already completed.`
      : error.message;
    return mcpToolResult({ ok: false, error: 'adapter_request_failed', message }, true);
  }
}

async function mcp(args) {
  const { profile, profilePath } = profilePathFromArgs(args);
  if (!existsSync(profilePath)) {
    console.error(JSON.stringify({ ok: false, status: 'missing_profile', profile, message: 'Run connect before mcp.' }));
    process.exitCode = 1;
    return;
  }

  const { parsed } = parseArgs(args);
  const tools = mcpToolDescriptors();
  let serverVersion = '0.0.0';
  try {
    serverVersion = String(JSON.parse(readFileSync(join(toolDir, '..', 'package.json'), 'utf8')).version || '0.0.0');
  } catch (error) {
    serverVersion = '0.0.0';
  }

  const respond = (id, result) => {
    process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', id, result })}\n`);
  };
  const respondError = (id, code, message) => {
    process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', id, error: { code, message } })}\n`);
  };

  const handleMessage = async (message, parseOk) => {
    if (!parseOk) {
      respondError(null, -32700, 'Parse error.');
      return;
    }

    if (!message || typeof message !== 'object' || Array.isArray(message)) {
      // Batches, scalars, and null are not Request objects; answer instead of
      // leaving a waiting client hanging.
      respondError(null, -32600, 'Invalid Request.');
      return;
    }

    const id = message.id !== undefined ? message.id : null;
    const method = typeof message.method === 'string' ? message.method : '';
    const params = typeof message.params === 'object' && message.params !== null && !Array.isArray(message.params) ? message.params : {};

    if (id === null) {
      // Notifications such as notifications/initialized need no response.
      return;
    }

    if (method === 'initialize') {
      // Respond only with a protocol version this server supports; echoing an
      // unsupported client version would falsely signal compatibility.
      respond(id, {
        protocolVersion: MCP_DEFAULT_PROTOCOL_VERSION,
        capabilities: { tools: { listChanged: false } },
        serverInfo: {
          name: 'npcink-openclaw-adapter',
          title: 'Npcink AI Client Adapter (governed MCP channel)',
          version: serverVersion,
        },
        instructions: 'Governed MCP channel over the Npcink AI Client Adapter. Reads run through approved WordPress abilities; writes are Core proposals that require human approval. Execution tools need an explicit intent argument and run only post-Core allowlisted executions.',
      });
      return;
    }

    if (method === 'ping') {
      respond(id, {});
      return;
    }

    if (method === 'tools/list') {
      respond(id, {
        tools: tools.map((tool) => ({ name: tool.name, description: tool.description, inputSchema: tool.inputSchema })),
      });
      return;
    }

    if (method === 'tools/call') {
      respond(id, await mcpCallTool(parsed, tools, params));
      return;
    }

    respondError(id, -32601, `Unknown method: ${method || '(empty)'}.`);
  };

  // Tool calls spawn a wrapper child process, so they serialize on one
  // chain: a client cannot spawn concurrent wrappers through this server
  // and responses stay bounded. Cheap protocol methods (initialize, ping,
  // tools/list) answer immediately instead of waiting behind a slow tool
  // call. The line is parsed once here and handed to handleMessage.
  let dispatchChain = Promise.resolve();
  const dispatchMessage = (line) => {
    let message;
    let parseOk = true;
    try {
      message = JSON.parse(line);
    } catch (error) {
      parseOk = false;
    }
    const isObjectMessage = !!message && typeof message === 'object' && !Array.isArray(message);
    const requestId = parseOk && isObjectMessage && message.id !== undefined ? message.id : null;
    const internalError = (error) => {
      respondError(requestId, -32603, `Internal error: ${error && error.message ? String(error.message) : 'unknown'}.`);
    };
    const run = () => handleMessage(message, parseOk);
    if (parseOk && isObjectMessage && message.method === 'tools/call') {
      dispatchChain = dispatchChain.then(run).catch(internalError);
    } else {
      Promise.resolve().then(run).catch(internalError);
    }
  };

  let buffer = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', (chunk) => {
    buffer += chunk;
    // Cap the pending (incomplete) line, not the whole buffer: complete
    // messages ahead of it stay processable. Count bytes, not UTF-16 code
    // units, so multibyte payloads cannot slip past the cap.
    const pendingLine = buffer.slice(buffer.lastIndexOf('\n') + 1);
    if (Buffer.byteLength(pendingLine) > MCP_MAX_LINE_BYTES) {
      respondError(null, -32602, `Message too large (limit ${MCP_MAX_LINE_BYTES} bytes).`);
      buffer = '';
      process.stdin.destroy();
      return;
    }
    let newlineIndex = buffer.indexOf('\n');
    while (newlineIndex >= 0) {
      const line = buffer.slice(0, newlineIndex).trim();
      buffer = buffer.slice(newlineIndex + 1);
      if (line) {
        dispatchMessage(line);
      }
      newlineIndex = buffer.indexOf('\n');
    }
  });
  // On EOF, flush any tail line and let Node exit naturally once every
  // in-flight request (wrapper child process) has answered. An explicit
  // process.exit() here would kill pending tool calls.
  process.stdin.on('end', () => {
    const line = buffer.trim();
    buffer = '';
    if (line) {
      dispatchMessage(line);
    }
  });
  // A dead MCP client surfaces as EPIPE on the next write. Exit non-zero:
  // exit code 0 would tell the parent this server finished cleanly while
  // in-flight tool calls were dropped without responses.
  process.stdin.on('error', () => {
    console.error('npcink-openclaw-adapter: stdin failed; exiting.');
    process.exit(1);
  });
  process.stdout.on('error', () => {
    process.exit(1);
  });
}

function requestCommonArgs(parsed) {
  const out = [`--profile=${parsed.get('profile') || 'default'}`];
  if (parsed.get('profile-file')) {
    out.push(`--profile-file=${parsed.get('profile-file')}`);
  }
  if (parsed.has('insecure-local-tls')) {
    out.push('--insecure-local-tls');
  }
  return out;
}

function inputPayloadFromArgs(parsed) {
  const inputFile = parsed.get('input-file') || '';
  const inputJson = parsed.get('input-json') || '';
  const inputStdin = parsed.has('input-stdin');
  const sources = [inputFile ? 1 : 0, inputJson ? 1 : 0, inputStdin ? 1 : 0].reduce((a, b) => a + b, 0);
  if (sources > 1) {
    throw new Error('Use only one of --input-file, --input-json, or --input-stdin.');
  }
  if (inputFile) {
    return JSON.parse(readFileSync(inputFile, 'utf8'));
  }
  if (inputJson) {
    return JSON.parse(inputJson);
  }
  if (inputStdin) {
    return JSON.parse(readFileSync(0, 'utf8'));
  }
  return {};
}

function boundsFromArgs(parsed) {
  const bounds = {};
  if (parsed.get('max-rows')) {
    bounds.max_rows = positiveInt(parsed.get('max-rows'));
  }
  if (parsed.get('tail-lines')) {
    bounds.tail_lines = positiveInt(parsed.get('tail-lines'));
  }
  const allowedFields = csvList(parsed.get('allowed-fields') || '');
  if (allowedFields.length > 0) {
    bounds.allowed_fields = allowedFields;
  }
  const deniedFields = csvList(parsed.get('denied-fields') || '');
  if (deniedFields.length > 0) {
    bounds.denied_fields = deniedFields;
  }
  if (parsed.has('one-time')) {
    bounds.one_time = true;
  }
  return bounds;
}

function positiveInt(value) {
  const parsed = Number.parseInt(String(value), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function csvList(value) {
  return String(value || '').split(',').map((item) => item.trim()).filter(Boolean);
}

function printCapturedResult(result) {
  const stdout = result.stdout.trim();
  const stderr = result.stderr.trim();
  if (stdout) {
    console.log(sanitizeOutputText(stdout));
  }
  if (stderr) {
    console.error(sanitizeOutputText(stderr));
  }
}

function sanitizeOutputText(text) {
  try {
    return JSON.stringify(redactOutput(JSON.parse(text)), null, 2);
  } catch (error) {
    return text
      .replace(/(key_id|connection_id|authorization|cookie|token|signature|password|secret)=?["']?[^\s,"']+/gi, '$1=[redacted]')
      .replace(/\/[^\s]*\.npcink-openclaw-adapter\/keypair-profiles\/[^\s"']+/g, '[redacted]');
  }
}

function redactOutput(value) {
  if (Array.isArray(value)) {
    return value.map((item) => redactOutput(item));
  }
  if (!value || typeof value !== 'object') {
    return redactScalar(value);
  }
  const out = {};
  for (const [key, item] of Object.entries(value)) {
    if (isSensitiveOutputKey(key)) {
      out[key] = '[redacted]';
    } else {
      out[key] = redactOutput(item);
    }
  }
  return out;
}

function redactScalar(value) {
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

function safeErrorMessage(stdout, stderr) {
  for (const text of [stdout, stderr]) {
    if (!text.trim()) {
      continue;
    }
    try {
      const parsed = JSON.parse(text);
      let message = String(parsed.message || parsed.error || parsed.code || 'Request failed.');
      // The wrapper now carries the server error data (reason, next_step,
      // retry_after, route-specific operator_feedback); surface a bounded
      // copy for MCP operators.
      const data = parsed && typeof parsed === 'object' && parsed.data && typeof parsed.data === 'object' && !Array.isArray(parsed.data)
        ? parsed.data
        : null;
      if (data && Object.keys(data).length > 0) {
        message += ` ${JSON.stringify(redactOutput(data)).slice(0, 2000)}`;
      }
      return sanitizeOutputText(message);
    } catch (error) {
      return sanitizeOutputText(text.trim().split('\n')[0]);
    }
  }
  return 'Request failed.';
}

if (command === 'connect') {
  await connect(commandArgs);
} else if (command === 'status') {
  await status(commandArgs);
} else if (command === 'mcp') {
  await mcp(commandArgs);
} else if (command === 'request') {
  await request(commandArgs);
} else if (command === 'read-request') {
  try {
    await readRequest(commandArgs);
  } catch (error) {
    console.error(JSON.stringify({ ok: false, error: 'wrapper_failed', message: error.message }, null, 2));
    process.exit(1);
  }
} else if (command === 'read-ability') {
  try {
    await readAbility(commandArgs);
  } catch (error) {
    console.error(JSON.stringify({ ok: false, error: 'wrapper_failed', message: error.message }, null, 2));
    process.exit(1);
  }
} else if (command === 'recipe') {
  try {
    await recipe(commandArgs);
  } catch (error) {
    console.error(JSON.stringify({ ok: false, error: 'wrapper_failed', message: error.message }, null, 2));
    process.exit(1);
  }
}
