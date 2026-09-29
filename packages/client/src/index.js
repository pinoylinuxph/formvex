export const clientPackage = '@formvex/client';

export {
  collectDiscoveryMetadata,
  discoverParameterSuggestions,
  runDiscovery,
} from './discovery.js';

export {
  collectSubmissionData,
  createSubmissionEnvelope,
  findFormCandidates,
  initializeFormIntegration,
  mapSubmissionResponse,
  resolveForm,
  SUBMISSION_SCHEMA_VERSION,
} from './form-integration.js';
