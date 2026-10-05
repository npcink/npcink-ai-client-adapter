import re

# ---------- Upstream_Dispatch service ----------
p = 'includes/Rest/Upstream_Dispatch.php'
s = open(p).read()

old = """	private $missing_dependency_for_route;
"""
assert old in s, 'props'
new = old + """
	/**
	 * Returns the request-scoped signed client fingerprint.
	 *
	 * @var callable
	 */
	private $signed_client_fingerprint_provider;
"""
s = s.replace(old, new, 1)

old = """	public function __construct( Signing_Auth $signing_auth, callable $emit_event, callable $missing_dependency_for_route ) {
		$this->signing_auth                 = $signing_auth;
		$this->emit_event                   = $emit_event;
		$this->missing_dependency_for_route = $missing_dependency_for_route;
	}"""
assert old in s, 'ctor'
new = """	public function __construct( Signing_Auth $signing_auth, callable $emit_event, callable $missing_dependency_for_route, callable $signed_client_fingerprint_provider ) {
		$this->signing_auth                 = $signing_auth;
		$this->emit_event                   = $emit_event;
		$this->missing_dependency_for_route = $missing_dependency_for_route;
		$this->signed_client_fingerprint_provider = $signed_client_fingerprint_provider;
	}"""
s = s.replace(old, new, 1)

old = "$response = $this->send( 'GET', '/npcink-governance-core/v1/capabilities' );"
assert old in s, 'cap fetch'
new = "$response = $this->send( 'GET', '/npcink-governance-core/v1/capabilities', array(), false, false, true, (string) call_user_func( $this->signed_client_fingerprint_provider ) );"
s = s.replace(old, new, 1)

old = "\t\t$response = rest_do_request( $request );\n\t\tif ( '' !== $token && 0 === strpos( $route, '/npcink-governance-core/v1/' ) ) {\n\t\t\twp_set_current_user( $user_id );\n\t\t}"
assert old in s, 'restore'
new = """		try {
			$response = rest_do_request( $request );
		} finally {
			if ( '' !== $token && 0 === strpos( $route, '/npcink-governance-core/v1/' ) ) {
				wp_set_current_user( $user_id );
			}
		}"""
s = s.replace(old, new, 1)

# repair the orphaned find docblock
find_doc = re.search(r'\t/\*\*\n\t \* Finds one capability row.*?\n\t \*/\n', s, re.S)
assert find_doc, 'find doc'
doc_text = find_doc.group(0)
s = s.replace(doc_text, '', 1)
anchor = "\tpublic function find_core_capability("
assert anchor in s
s = s.replace(anchor, doc_text + anchor, 1)
open(p, 'w').write(s)
print('service fixed')

# ---------- Controller ----------
p = 'includes/Rest/Controller.php'
s = open(p).read()

old = """			function ( string $route ) {
				return $this->missing_dependency_for_route( $route );
			}
		);"""
assert old in s, 'ctor wire'
new = """			function ( string $route ) {
				return $this->missing_dependency_for_route( $route );
			},
			function (): string {
				return $this->current_signed_client_fingerprint();
			}
		);"""
s = s.replace(old, new, 1)

old = """	/**
	 * Finds one Core capability by ability id.
	 *
	 * @param string $ability_id Ability id.
	 * @return array<string,mixed>
	 */
	private function find_core_capability( string $ability_id ) {"""
assert old in s, 'find wrapper doc'
new = """	/**
	 * Finds one Core capability by ability id.
	 *
	 * @param string $ability_id Ability id.
	 * @return array<string,mixed>|WP_Error Capability row or discovery error.
	 */
	private function find_core_capability( string $ability_id ) {"""
s = s.replace(old, new, 1)

# repair stacked docblock before dispatch_upstream_with_runtime_context
orphan = re.search(r'\t/\*\*\n\t \* Dispatches an upstream request while adding governance context to AI logs\.\n(?:\t \*[^\n]*\n)*\t \*/\n(?=\t/\*\*\n\t \* Sends one upstream REST request with request-scoped identity context\.)', s)
assert orphan, 'orphan runtime doc'
s = s.replace(orphan.group(0), '', 1)
anchor = "\tprivate function dispatch_upstream_with_runtime_context("
assert anchor in s
s = s.replace(anchor, orphan.group(0) + anchor, 1)
open(p, 'w').write(s)
print('controller fixed')

# ---------- run.php: re-anchor the two windows to the service declarations ----------
p = 'tests/run.php'
s = open(p).read()
old = "\t$upstream_dispatch = substr( $controller, (int) strpos( $controller, 'private function dispatch_upstream( string' ), 1600 );"
assert old in s, 'dispatch window anchor'
new = "\t$upstream_dispatch = substr( $controller_contract, (int) strpos( $controller_contract, 'public function send( string $method' ), 1600 );\n\tmaa_adapter_assert( false !== strpos( $upstream_dispatch, 'x-npcink-adapter-signed-client-fingerprint' ), 'Adapter forwards signed client fingerprint to Core app-token requests.' );"
s = s.replace(old, "\t" + new.lstrip('\t') if False else new, 1)
# remove the now-duplicated following assertion line for signed fingerprint
s = s.replace("\tmaa_adapter_assert( false !== strpos( $upstream_dispatch, 'x-npcink-adapter-signed-client-fingerprint' ), 'Adapter forwards signed client fingerprint to Core app-token requests.' );\n\tmaa_adapter_assert( false !== strpos( $upstream_dispatch, 'x-npcink-adapter-signed-client-fingerprint' ), 'Adapter forwards signed client fingerprint to Core app-token requests.' );",
              "\tmaa_adapter_assert( false !== strpos( $upstream_dispatch, 'x-npcink-adapter-signed-client-fingerprint' ), 'Adapter forwards signed client fingerprint to Core app-token requests.' );", 1)

old = "\t$core_token_source = substr( $controller, (int) strpos( $controller, 'private function core_app_token_source' ), 900 );"
assert old in s, 'token window anchor'
new = "\t$core_token_source = substr( $controller_contract, (int) strpos( $controller_contract, 'public function core_app_token_source' ), 900 );"
s = s.replace(old, new, 1)
open(p, 'w').write(s)
print('windows re-anchored to service declarations')
