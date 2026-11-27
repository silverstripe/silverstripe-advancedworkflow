/**
 * How the panel should read a `workflowData` response.
 *
 * A 4xx is not a fault worth reporting: it means workflow does not apply to
 * this record (unsupported class, missing parameters, no CMS permission), so
 * the panel renders nothing. Only a server fault or a dropped request means
 * "workflow is enabled but we could not read its status".
 */
export const RESPONSE_OK = 'ok';
export const RESPONSE_NOT_APPLICABLE = 'not-applicable';
export const RESPONSE_FAILED = 'failed';

export const classifyWorkflowResponse = (status) => {
  if (status >= 200 && status < 300) {
    return RESPONSE_OK;
  }

  if (status >= 400 && status < 500) {
    return RESPONSE_NOT_APPLICABLE;
  }

  return RESPONSE_FAILED;
};

const HTTP_FORBIDDEN = 403;

export const LOAD_FAILURE = Object.freeze({
  FORBIDDEN: 'forbidden',
  UNAVAILABLE: 'unavailable',
  NETWORK: 'network',
});

/**
 * Why a workflow definition could not be loaded into the builder.
 *
 * @param {number|null} status HTTP status, or null when the request failed.
 * @returns {string} One of LOAD_FAILURE.
 */
export const classifyLoadFailure = (status) => {
  if (status === null) {
    return LOAD_FAILURE.NETWORK;
  }

  return status === HTTP_FORBIDDEN ? LOAD_FAILURE.FORBIDDEN : LOAD_FAILURE.UNAVAILABLE;
};
