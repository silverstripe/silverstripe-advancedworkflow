import Config from 'lib/Config';
import { ENDPOINT, endpointUrl, getJson, postJson } from '../src/lib/api';

const SECTION = 'Symbiote\\AdvancedWorkflow\\Controllers\\WorkflowBuilderController';
const BASE = 'http://example.test/sub/admin/workflow-ui';

const jsonResponse = (status, body) => ({
  ok: status >= 200 && status < 300,
  status,
  json: () => Promise.resolve(body),
});

describe('workflow api', () => {
  beforeEach(() => {
    Config.setSections([{
      name: SECTION,
      endpoints: {
        [ENDPOINT.WORKFLOW_DATA]: `${BASE}/workflowData`,
        [ENDPOINT.SAVE_WORKFLOW]: `${BASE}/saveWorkflow`,
      },
    }]);
    global.fetch = jest.fn();
  });

  it('builds URLs from the admin client config, keeping a subdirectory base', () => {
    expect(endpointUrl(ENDPOINT.WORKFLOW_DATA, { ID: 5, ClassName: 'A\\B' }))
      .toBe(`${BASE}/workflowData?ID=5&ClassName=A%5CB`);
  });

  it('throws when the endpoint is not configured', () => {
    Config.setSections([]);
    expect(() => endpointUrl(ENDPOINT.WORKFLOW_DATA)).toThrow(/workflowData/);
  });

  it('returns the status without parsing an error response', async () => {
    global.fetch.mockResolvedValue(jsonResponse(403, { error: 'nope' }));

    await expect(getJson(ENDPOINT.WORKFLOW_DATA)).resolves.toEqual({ ok: false, status: 403, data: null });
  });

  it('parses a successful response', async () => {
    global.fetch.mockResolvedValue(jsonResponse(200, { workflow: null }));

    await expect(getJson(ENDPOINT.WORKFLOW_DATA)).resolves.toEqual({ ok: true, status: 200, data: { workflow: null } });
  });

  it('sends the CMS security token with a save', async () => {
    global.fetch.mockResolvedValue(jsonResponse(200, {}));

    await postJson(ENDPOINT.SAVE_WORKFLOW, { id: 1 });

    const [url, options] = global.fetch.mock.calls[0];
    expect(url).toBe(`${BASE}/saveWorkflow`);
    expect(options.method).toBe('POST');
    expect(options.headers['X-SecurityID']).toBe('test-token');
    expect(JSON.parse(options.body)).toEqual({ id: 1 });
  });
});
