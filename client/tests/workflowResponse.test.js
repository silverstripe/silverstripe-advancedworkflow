import {
  classifyLoadFailure,
  classifyWorkflowResponse,
  LOAD_FAILURE,
  RESPONSE_FAILED,
  RESPONSE_NOT_APPLICABLE,
  RESPONSE_OK,
} from '../src/lib/workflowResponse';

describe('classifyWorkflowResponse', () => {
  it('treats a 200 as readable workflow data', () => {
    expect(classifyWorkflowResponse(200)).toBe(RESPONSE_OK);
  });

  // Regression: a record the API cannot answer for is a normal state, not a
  // fault — the panel must stay silent instead of showing "Unable to load
  // workflow status."
  it.each([400, 403, 404])('treats a %i as workflow not applying here', (status) => {
    expect(classifyWorkflowResponse(status)).toBe(RESPONSE_NOT_APPLICABLE);
  });

  it.each([500, 502, 0])('treats a %i as a genuine failure', (status) => {
    expect(classifyWorkflowResponse(status)).toBe(RESPONSE_FAILED);
  });
});

describe('classifyLoadFailure', () => {
  it('reports a 403 as a permission problem', () => {
    expect(classifyLoadFailure(403)).toBe(LOAD_FAILURE.FORBIDDEN);
  });

  it('reports other statuses as unavailable', () => {
    expect(classifyLoadFailure(404)).toBe(LOAD_FAILURE.UNAVAILABLE);
    expect(classifyLoadFailure(500)).toBe(LOAD_FAILURE.UNAVAILABLE);
  });

  it('reports a failed request as a network problem', () => {
    expect(classifyLoadFailure(null)).toBe(LOAD_FAILURE.NETWORK);
  });
});
