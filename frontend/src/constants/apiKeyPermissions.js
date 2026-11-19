// Mirrors App\Application\ApiKeys\ApiKeyService::ALLOWED_PERMISSIONS on the backend.
export const ApiKeyService = {
  ALLOWED_PERMISSIONS: [
    '*',
    'monitors:read',
    'monitors:write',
    'monitors:operate',
    'secrets:manage',
    'api_keys:manage',
    'tenant:admin',
  ],
};
