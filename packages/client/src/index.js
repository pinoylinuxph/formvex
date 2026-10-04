export const clientPackage = '@formvex/client';

export {
  collectDiscoveryMetadata,
  discoverParameterSuggestions,
  runDiscovery,
} from './discovery.js';

export {
  collectSubmissionData,
  collectFormChangeObservation,
  createSubmissionEnvelope,
  findFormCandidates,
  initializeQualificationIntegration,
  initializeFormIntegration,
  mapSubmissionResponse,
  resolveForm,
  SUBMISSION_SCHEMA_VERSION,
} from './form-integration.js';

export { runQualification, QUALIFICATION_SCHEMA_VERSION } from './qualification.js';
