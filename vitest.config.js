import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/unit/Client/**/*.test.js', 'tests/unit/PlatformUi/**/*.test.js'],
  },
});
