import { describe, expect, it } from 'vitest';

import { hubPlatformAsset } from '../../../apps/hub/assets/app.js';
import { spokeAdminAsset } from '../../../apps/spoke/assets/app.js';
import { clientPackage } from '../../../packages/client/src/index.js';
import { platformUiPackage } from '../../../packages/platform-ui/assets/index.js';

describe('JavaScript workspace entry points', () => {
  it('exports every approved asset boundary', () => {
    expect(clientPackage).toBe('@formvex/client');
    expect(platformUiPackage).toBe('@formvex/platform-ui');
    expect(spokeAdminAsset).toBe('formvex/spoke-admin');
    expect(hubPlatformAsset).toBe('formvex/hub-platform');
  });
});
