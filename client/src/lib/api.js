import Config from 'lib/Config';

/**
 * Endpoints of WorkflowBuilderController. Their URLs come from the admin
 * client config, so they follow the site's base URL and admin route.
 */
const CONTROLLER_SECTION = 'Symbiote\\AdvancedWorkflow\\Controllers\\WorkflowBuilderController';

export const ENDPOINT = Object.freeze({
  WORKFLOW_META: 'workflowMeta',
  SEARCH_USERS: 'searchUsers',
  WORKFLOW_DATA: 'workflowData',
  LOAD_DEFINITION: 'loadDefinition',
  SAVE_WORKFLOW: 'saveWorkflow',
});

const SECURITY_TOKEN_KEY = 'SecurityID';
const SECURITY_TOKEN_HEADER = 'X-SecurityID';

/**
 * @param {string} endpoint One of ENDPOINT.
 * @param {Object<string, string|number>} [params] Query string parameters.
 * @returns {string}
 * @throws {Error} When the admin client config has no URL for the endpoint.
 */
export const endpointUrl = (endpoint, params = {}) => {
  const section = Config.getSection(CONTROLLER_SECTION);
  const url = section && section.endpoints ? section.endpoints[endpoint] : null;

  if (!url) {
    throw new Error(`No URL configured for workflow endpoint "${endpoint}"`);
  }

  const query = new URLSearchParams(params).toString();

  return query ? `${url}?${query}` : url;
};

/**
 * GET an endpoint. The body is parsed only for a 2xx response; other statuses
 * are returned for the caller to interpret. Rejects on a network error or a
 * 2xx body that is not JSON.
 *
 * @param {string} endpoint One of ENDPOINT.
 * @param {Object<string, string|number>} [params]
 * @returns {Promise<{ok: boolean, status: number, data: *}>}
 */
export const getJson = async (endpoint, params = {}) => {
  const response = await fetch(endpointUrl(endpoint, params), { credentials: 'same-origin' });
  const data = response.ok ? await response.json() : null;

  return { ok: response.ok, status: response.status, data };
};

/**
 * POST JSON to an endpoint with the CMS security token.
 *
 * @param {string} endpoint One of ENDPOINT.
 * @param {*} body
 * @returns {Promise<Response>}
 */
export const postJson = (endpoint, body) => fetch(endpointUrl(endpoint), {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    [SECURITY_TOKEN_HEADER]: Config.get(SECURITY_TOKEN_KEY),
  },
  body: JSON.stringify(body),
});
