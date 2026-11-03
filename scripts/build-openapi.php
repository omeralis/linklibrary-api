<?php

/**
 * Generates docs/openapi.yaml (OpenAPI 3.1) from the compact definitions below.
 * Run: php scripts/build-openapi.php
 * The test suite verifies that every /api/v1 route is documented.
 */

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$ref = fn (string $name) => ['$ref' => "#/components/schemas/{$name}"];
$envelope = fn (array $data, ?array $meta = null) => ['allOf' => array_values(array_filter([
    $ref('Envelope'),
    ['type' => 'object', 'properties' => array_filter(['data' => $data, 'meta' => $meta])],
]))];
$list = fn (string $item, array $extraMeta = []) => $envelope(
    ['type' => 'array', 'items' => $ref($item)],
    $extraMeta === [] ? $ref('PaginationMeta') : ['allOf' => [$ref('PaginationMeta'), ['type' => 'object', 'properties' => $extraMeta]]],
);
$json = fn (array $schema) => ['content' => ['application/json' => ['schema' => $schema]]];
$errorResponses = [
    '401' => ['$ref' => '#/components/responses/Unauthenticated'],
    '403' => ['$ref' => '#/components/responses/Forbidden'],
    '404' => ['$ref' => '#/components/responses/NotFound'],
    '422' => ['$ref' => '#/components/responses/ValidationError'],
    '429' => ['$ref' => '#/components/responses/RateLimited'],
];
$pathParam = fn (string $name, string $desc, string $format = 'uuid') => ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $desc, 'schema' => array_filter(['type' => 'string', 'format' => $format === '' ? null : $format])];
$query = fn (string $name, array $schema, string $desc = '') => array_filter(['name' => $name, 'in' => 'query', 'required' => false, 'description' => $desc ?: null, 'schema' => $schema]);
$pageParams = [$query('page', ['type' => 'integer', 'minimum' => 1]), $query('per_page', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50])];
$linkFilters = [
    $query('q', ['type' => 'string', 'minLength' => 2, 'maxLength' => 200], 'Full-text query (Arabic/English)'),
    $query('category', ['type' => 'string'], 'Category slug (parent includes children)'),
    $query('tag', ['type' => 'string']),
    $query('tags[]', ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5]),
    $query('source_type', $ref('SourceType')),
];
$publicId = $pathParam('publicId', 'Saved link public identifier');

$op = function (string $tag, string $summary, array $responses, array $extra = []) use ($errorResponses) {
    $codes = array_keys($responses);
    $errors = array_diff_key($errorResponses, array_flip($codes));
    if (($extra['security'] ?? null) === []) {
        unset($errors['401'], $errors['403']);
    }

    return array_merge(['tags' => [$tag], 'summary' => $summary], $extra, ['responses' => $responses + $errors]);
};
$ok = fn (array $schema, string $desc = 'OK') => ['description' => $desc] + $json($schema);
$message = $ok($ref('Envelope'));
$public = ['security' => []];
$body = fn (array $props, array $required = []) => ['requestBody' => ['required' => true] + $json(array_filter(['type' => 'object', 'properties' => $props, 'required' => $required ?: null]))];

$str = fn (array $extra = []) => ['type' => 'string'] + $extra;
$paths = [
    // ---------------------------------------------------------------- Auth
    '/auth/register' => ['post' => $op('Auth', 'Register a new account', ['201' => $ok($envelope($ref('AuthResult')), 'Created')], $public + $body([
        'name' => $str(['maxLength' => 100]), 'username' => $str(['pattern' => '^[a-z0-9_]{3,30}$']), 'email' => $str(['format' => 'email']),
        'password' => $str(['minLength' => 10]), 'password_confirmation' => $str(), 'locale' => ['enum' => ['ar', 'en']],
        'device_name' => $str(['description' => 'Token mode only (non-SPA clients)']),
    ], ['name', 'username', 'email', 'password', 'password_confirmation']))],
    '/auth/login' => ['post' => $op('Auth', 'Sign in (session cookie for the SPA, bearer token otherwise)', ['200' => $ok($envelope($ref('AuthResult')))], $public + $body([
        'email' => $str(['format' => 'email']), 'password' => $str(), 'remember' => ['type' => 'boolean'], 'device_name' => $str(),
    ], ['email', 'password']))],
    '/auth/logout' => ['post' => $op('Auth', 'Sign out (revokes the current token or session)', ['200' => $message])],
    '/auth/me' => ['get' => $op('Auth', 'Current authenticated user', ['200' => $ok($envelope($ref('User')))])],
    '/auth/forgot-password' => ['post' => $op('Auth', 'Request a password reset link (identical response for unknown addresses)', ['202' => $message], $public + $body(['email' => $str(['format' => 'email'])], ['email']))],
    '/auth/reset-password' => ['post' => $op('Auth', 'Reset the password with an e-mailed token', ['200' => $message], $public + $body([
        'token' => $str(), 'email' => $str(['format' => 'email']), 'password' => $str(), 'password_confirmation' => $str(),
    ], ['token', 'email', 'password', 'password_confirmation']))],
    '/auth/email/verification-notification' => ['post' => $op('Auth', 'Resend the verification e-mail', ['202' => $message])],
    '/auth/email/verify/{id}/{hash}' => ['get' => $op('Auth', 'Verify an e-mail address (signed link parameters from the e-mail)', ['200' => $message], $public + ['parameters' => [
        $pathParam('id', 'User public id'), $pathParam('hash', 'SHA-1 of the e-mail address', ''),
        $query('expires', ['type' => 'integer'], 'Must be sent before signature, exactly as received'), $query('signature', ['type' => 'string']),
    ]])],

    // ---------------------------------------------------------------- Profile
    '/me' => [
        'get' => $op('Profile', 'Get my account', ['200' => $ok($envelope($ref('User')))]),
        'patch' => $op('Profile', 'Update profile & preferences (changing e-mail requires current_password)', ['200' => $ok($envelope($ref('User')))], $body([
            'name' => $str(), 'username' => $str(), 'email' => $str(['format' => 'email']), 'current_password' => $str(),
            'bio' => $str(['maxLength' => 500]), 'locale' => ['enum' => ['ar', 'en']], 'theme' => ['enum' => ['system', 'light', 'dark']], 'profile_public' => ['type' => 'boolean'],
        ])),
        'delete' => $op('Profile', 'Delete my account permanently', ['200' => $message], $body(['password' => $str(), 'confirmation' => ['const' => 'DELETE']], ['password', 'confirmation'])),
    ],
    '/me/password' => ['patch' => $op('Profile', 'Change password (signs out other sessions)', ['200' => $message], $body(['current_password' => $str(), 'password' => $str(), 'password_confirmation' => $str()], ['current_password', 'password', 'password_confirmation']))],
    '/me/avatar' => [
        'post' => $op('Profile', 'Upload avatar (JPEG/PNG/WebP/GIF, re-encoded server-side)', ['200' => $ok($envelope($ref('User')))], ['requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => ['image' => ['type' => 'string', 'format' => 'binary']], 'required' => ['image']]]]]]),
        'delete' => $op('Profile', 'Remove avatar', ['200' => $ok($envelope($ref('User')))]),
    ],
    '/me/sessions' => ['get' => $op('Profile', 'List browser sessions (database session driver)', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Session')], ['type' => 'object', 'properties' => ['supported' => ['type' => 'boolean']]]))])],
    '/me/sessions/{sessionId}' => ['delete' => $op('Profile', 'Revoke a session by fingerprint', ['200' => $message], ['parameters' => [$pathParam('sessionId', 'SHA-256 session fingerprint', '')]])],
    '/me/sessions/revoke-others' => ['post' => $op('Profile', 'Sign out all other sessions and tokens', ['200' => $message], $body(['current_password' => $str()], ['current_password']))],
    '/me/tokens' => ['get' => $op('Profile', 'List API tokens', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('ApiToken')]))])],
    '/me/tokens/{tokenId}' => ['delete' => $op('Profile', 'Revoke an API token', ['200' => $message], ['parameters' => [['name' => 'tokenId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]]])],
    '/me/invitations' => ['get' => $op('Sharing', 'Pending invitations addressed to my verified e-mail (empty with meta.email_verified=false when unverified)', ['200' => $ok($list('ReceivedInvitation', ['email_verified' => ['type' => 'boolean']]))], ['parameters' => $pageParams])],
    '/me/invitations/{invitationId}/accept' => ['post' => $op('Sharing', 'Accept an invitation in-app', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$pathParam('invitationId', 'Invitation id')]])],

    // ---------------------------------------------------------------- Links
    '/links' => [
        'get' => $op('Links', 'List my saved links', ['200' => $ok($list('SavedLink'))], ['parameters' => [...$linkFilters, ...$pageParams,
            $query('favorite', ['type' => 'boolean']), $query('visibility', $ref('Visibility')), $query('security_status', $ref('SecurityStatus')),
            $query('collection', ['type' => 'string', 'format' => 'uuid']), $query('trashed', ['enum' => ['only', 'with']]),
            $query('sort', ['enum' => ['latest', 'oldest', 'title', 'popular', 'relevance']]),
        ]]),
        'post' => $op('Links', 'Save a link (always PRIVATE; processing is asynchronous)', [
            '201' => $ok($envelope($ref('SavedLink')), 'Created'),
            '200' => $ok($envelope($ref('SavedLink'), ['type' => 'object', 'properties' => ['already_saved' => ['type' => 'boolean']]]), 'Already saved (idempotent)'),
        ], $body([
            'url' => $str(['maxLength' => 2048]), 'custom_title' => $str(), 'custom_description' => $str(), 'custom_source_type' => $ref('SourceType'),
            'notes' => $str(), 'category' => $str(), 'tags' => ['type' => 'array', 'items' => $str(), 'maxItems' => 20],
            'collections' => ['type' => 'array', 'items' => $str(['format' => 'uuid'])],
        ], ['url'])),
    ],
    '/links/{publicId}' => [
        'get' => $op('Links', 'View a saved link (owner, invited viewer, or public)', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId]]),
        'patch' => $op('Links', 'Update manual metadata (owner)', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId]] + $body([
            'custom_title' => $str(['nullable' => true]), 'custom_description' => $str(), 'custom_image_url' => $str(), 'custom_source_type' => $ref('SourceType'),
            'notes' => $str(), 'category' => $str(), 'tags' => ['type' => 'array', 'items' => $str()],
        ])),
        'delete' => $op('Links', 'Delete (soft by default; ?permanent=1 for permanent). Revokes all grants and invitations.', ['200' => $message], ['parameters' => [$publicId, $query('permanent', ['type' => 'boolean'])]]),
    ],
    '/links/{publicId}/restore' => ['post' => $op('Links', 'Restore a deleted link (restored as PRIVATE, grants are not restored)', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId]])],
    '/links/{publicId}/favorite' => ['post' => $op('Links', 'Toggle or set favorite', ['200' => $message], ['parameters' => [$publicId], 'requestBody' => ['required' => false] + $json(['type' => 'object', 'properties' => ['favorite' => ['type' => 'boolean']]])])],
    '/links/{publicId}/visibility' => ['patch' => $op('Links', 'Change visibility (PUBLIC requires verified e-mail and CLEAR security status)', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId]] + $body(['visibility' => $ref('Visibility')], ['visibility']))],
    '/links/{publicId}/sharing-settings' => ['patch' => $op('Sharing', 'Enable/disable resharing by viewers', ['200' => $message], ['parameters' => [$publicId]] + $body(['allow_resharing' => ['type' => 'boolean']], ['allow_resharing']))],
    '/links/{publicId}/tags' => ['post' => $op('Links', 'Add tags', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId]] + $body(['tags' => ['type' => 'array', 'items' => $str()]], ['tags']))],
    '/links/{publicId}/tags/{tagSlug}' => ['delete' => $op('Links', 'Remove a tag', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId, $pathParam('tagSlug', 'Tag slug', '')]])],
    '/links/{publicId}/cover' => [
        'post' => $op('Links', 'Upload a custom cover image', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId], 'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => ['image' => ['type' => 'string', 'format' => 'binary']]]]]]]),
        'delete' => $op('Links', 'Remove the custom cover image', ['200' => $ok($envelope($ref('SavedLink')))], ['parameters' => [$publicId]]),
    ],
    '/links/{publicId}/reanalyze' => ['post' => $op('Links', 'Re-run extraction and AI (manual overrides preserved)', ['202' => $ok($envelope($ref('Processing')))], ['parameters' => [$publicId]])],
    '/links/{publicId}/processing-status' => ['get' => $op('Links', 'Processing status', ['200' => $ok($envelope($ref('Processing')))], ['parameters' => [$publicId]])],
    '/links/{publicId}/open' => ['get' => $op('Links', 'Platform-mediated open: returns the destination when the security policy allows it', ['200' => $ok($envelope(['type' => 'object', 'properties' => ['url' => $str(), 'security_status' => $ref('SecurityStatus'), 'warning' => ['type' => 'boolean']]]))], ['parameters' => [$publicId]])],

    // ---------------------------------------------------------------- Security
    '/links/{publicId}/security' => ['get' => $op('Security', 'Security status, scan history and appeals (owner)', ['200' => $ok($envelope($ref('SecurityDetails')))], ['parameters' => [$publicId]])],
    '/links/{publicId}/security/rescan' => ['post' => $op('Security', 'Request a security rescan', ['202' => $ok($envelope($ref('SecurityScan')))], ['parameters' => [$publicId]])],
    '/links/{publicId}/security/appeal' => ['post' => $op('Security', 'Request a security review (SUSPICIOUS/MALICIOUS only)', ['201' => $ok($envelope($ref('Appeal')), 'Created'), '409' => ['$ref' => '#/components/responses/Conflict']], ['parameters' => [$publicId]] + $body(['reason' => $str(['minLength' => 10, 'maxLength' => 2000])], ['reason']))],

    // ---------------------------------------------------------------- Sharing
    '/links/{publicId}/invitations' => [
        'get' => $op('Sharing', 'List invitations (owner: all; viewer: own)', ['200' => $ok($list('Invitation'))], ['parameters' => [$publicId, ...$pageParams]]),
        'post' => $op('Sharing', 'Invite by e-mail (requires verified e-mail and CLEAR link)', ['201' => $ok($envelope($ref('Invitation')), 'Created'), '409' => ['$ref' => '#/components/responses/Conflict']], ['parameters' => [$publicId]] + $body(['email' => $str(['format' => 'email'])], ['email'])),
    ],
    '/links/{publicId}/invitations/{invitationId}' => ['delete' => $op('Sharing', 'Revoke a pending invitation', ['200' => $message], ['parameters' => [$publicId, $pathParam('invitationId', 'Invitation id')]])],
    '/invitations/{token}/accept' => ['post' => $op('Sharing', 'Accept an invitation (authenticated, verified e-mail must match)', ['200' => $ok($envelope($ref('SavedLink'))), '409' => ['$ref' => '#/components/responses/Conflict'], '410' => ['description' => 'Invitation expired'] + $json($ref('Error'))], ['parameters' => [$pathParam('token', '64-hex-character token from the e-mail', '')]])],
    '/links/{publicId}/access' => ['get' => $op('Sharing', 'Access graph (owner: all grants; viewer: grants they issued)', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Grant')], ['type' => 'object', 'properties' => ['allow_resharing' => ['type' => 'boolean'], 'viewer_count' => ['type' => 'integer']]]))], ['parameters' => [$publicId]])],
    '/links/{publicId}/access/{grantId}' => ['delete' => $op('Sharing', 'Revoke a grant and all dependent grants (owner, grantor, or the grantee leaving). Users who still hold a valid grant through an independent path keep access and are neither counted nor notified.', ['200' => $ok($envelope(['type' => 'object', 'required' => ['revoked_users'], 'properties' => ['revoked_users' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of users who lost all access to the link']]]))], ['parameters' => [$publicId, $pathParam('grantId', 'Grant id')]])],
    '/shared-with-me' => ['get' => $op('Sharing', 'Links shared with me (viewer representation)', ['200' => $ok($list('SavedLink'))], ['parameters' => [...$linkFilters, $query('sort', ['enum' => ['latest', 'oldest', 'title', 'popular', 'relevance']]), ...$pageParams]])],

    // ---------------------------------------------------------------- Collections
    '/collections' => [
        'get' => $op('Collections', 'List my collections', ['200' => $ok($list('Collection'))], ['parameters' => $pageParams]),
        'post' => $op('Collections', 'Create a collection', ['201' => $ok($envelope($ref('Collection')), 'Created')], $body(['name' => $str(), 'description' => $str(), 'visibility' => $ref('Visibility'), 'cover_image_url' => $str(), 'sort_order' => ['type' => 'integer']], ['name'])),
    ],
    '/collections/{publicId}' => [
        'get' => $op('Collections', 'View my collection with items', ['200' => $ok($envelope($ref('Collection')))], ['parameters' => [$pathParam('publicId', 'Collection id')]]),
        'patch' => $op('Collections', 'Update a collection', ['200' => $ok($envelope($ref('Collection')))], ['parameters' => [$pathParam('publicId', 'Collection id')]] + $body(['name' => $str(), 'description' => $str(), 'visibility' => $ref('Visibility'), 'cover_image_url' => $str(), 'sort_order' => ['type' => 'integer']])),
        'delete' => $op('Collections', 'Delete a collection (links are kept)', ['200' => $message], ['parameters' => [$pathParam('publicId', 'Collection id')]]),
    ],
    '/collections/{publicId}/links' => ['post' => $op('Collections', 'Add my saved links', ['200' => $message], ['parameters' => [$pathParam('publicId', 'Collection id')]] + $body(['links' => ['type' => 'array', 'items' => $str(['format' => 'uuid']), 'maxItems' => 100]], ['links']))],
    '/collections/{publicId}/links/{savedLinkPublicId}' => ['delete' => $op('Collections', 'Remove a link from the collection', ['200' => $message], ['parameters' => [$pathParam('publicId', 'Collection id'), $pathParam('savedLinkPublicId', 'Saved link id')]])],
    '/collections/{publicId}/reorder' => ['patch' => $op('Collections', 'Reorder items', ['200' => $message], ['parameters' => [$pathParam('publicId', 'Collection id')]] + $body(['links' => ['type' => 'array', 'items' => $str(['format' => 'uuid'])]], ['links']))],

    // ---------------------------------------------------------------- Taxonomy & search
    '/categories' => ['get' => $op('Taxonomy', 'Active categories', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Category')]))])],
    '/tags' => ['get' => $op('Taxonomy', 'My tags with usage counts (prefix filter)', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Tag')]))], ['parameters' => [$query('q', ['type' => 'string'])]])],
    '/search' => ['get' => $op('Search', 'Search my links (incl. notes), links shared with me (public fields), or both; or my collections (type=collections returns Collection items)', ['200' => $ok($list('SavedLink', ['scope' => ['enum' => ['own', 'shared', 'all']]]))], ['parameters' => [
        $query('q', ['type' => 'string', 'minLength' => 2, 'maxLength' => 200]), $query('scope', ['enum' => ['own', 'shared', 'all']]), $query('type', ['enum' => ['links', 'collections']]),
        ...array_slice($linkFilters, 1), $query('favorite', ['type' => 'boolean']), $query('sort', ['enum' => ['relevance', 'latest', 'popular']]), ...$pageParams,
    ]])],

    // ---------------------------------------------------------------- Notifications
    '/notifications' => ['get' => $op('Notifications', 'List notifications', ['200' => $ok($list('Notification'))], ['parameters' => [$query('unread', ['type' => 'boolean']), ...$pageParams]])],
    '/notifications/unread-count' => ['get' => $op('Notifications', 'Unread count', ['200' => $ok($envelope(['type' => 'object', 'properties' => ['unread' => ['type' => 'integer']]]))])],
    '/notifications/{id}/read' => ['patch' => $op('Notifications', 'Mark as read', ['200' => $ok($envelope($ref('Notification')))], ['parameters' => [$pathParam('id', 'Notification id')]])],
    '/notifications/read-all' => ['post' => $op('Notifications', 'Mark all as read', ['200' => $message])],

    // ---------------------------------------------------------------- Public
    '/public/links' => ['get' => $op('Public', 'Discover public links (latest / popular)', ['200' => $ok($list('PublicSavedLink'))], $public + ['parameters' => [...$linkFilters, $query('language', ['enum' => ['ar', 'en']]), $query('sort', ['enum' => ['latest', 'popular', 'relevance']]), ...$pageParams]])],
    '/public/links/{publicId}' => ['get' => $op('Public', 'View a public link', ['200' => $ok($envelope($ref('PublicSavedLink')))], $public + ['parameters' => [$publicId]])],
    '/public/features' => ['get' => $op('Public', 'Feature flags (temporary security-scanning and sharing policy)', ['200' => $ok($envelope(['type' => 'object', 'properties' => ['security_scanning' => ['type' => 'string', 'enum' => ['COMING_SOON', 'AVAILABLE']], 'unverified_sharing' => ['type' => 'boolean']]]))], $public)],
    '/public/categories' => ['get' => $op('Public', 'Categories with public link counts', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Category')]))], $public)],
    '/public/tags' => ['get' => $op('Public', 'Tags used by public links', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Tag')]))], $public + ['parameters' => [$query('q', ['type' => 'string'])]])],
    '/public/users/{username}' => ['get' => $op('Public', 'Public profile', ['200' => $ok($envelope($ref('PublicUser')))], $public + ['parameters' => [$pathParam('username', 'Username', '')]])],
    '/public/users/{username}/links' => ['get' => $op('Public', "A user's public links", ['200' => $ok($list('PublicSavedLink'))], $public + ['parameters' => [$pathParam('username', 'Username', ''), ...$linkFilters, $query('language', ['enum' => ['ar', 'en']]), $query('sort', ['enum' => ['latest', 'popular', 'relevance']]), ...$pageParams]])],
    '/public/users/{username}/collections' => ['get' => $op('Public', "A user's public collections", ['200' => $ok($list('Collection'))], $public + ['parameters' => [$pathParam('username', 'Username', ''), ...$pageParams]])],
    '/public/collections/{publicId}' => ['get' => $op('Public', 'Public collection: data = its publicly eligible links, meta.collection = the collection', ['200' => $ok($list('PublicSavedLink', ['collection' => $ref('Collection')]))], $public + ['parameters' => [$pathParam('publicId', 'Collection id'), ...$pageParams]])],
    '/public/search' => ['get' => $op('Public', 'Search public links (q required)', ['200' => $ok($list('PublicSavedLink'))], $public + ['parameters' => [...$linkFilters, $query('language', ['enum' => ['ar', 'en']]), $query('sort', ['enum' => ['latest', 'popular', 'relevance']]), ...$pageParams]])],

    // ---------------------------------------------------------------- Admin
    '/admin/security/appeals' => ['get' => $op('Admin', 'List security appeals', ['200' => $ok($list('Appeal'))], ['parameters' => [$query('status', ['enum' => ['PENDING', 'APPROVED', 'REJECTED']]), ...$pageParams]])],
    '/admin/security/appeals/{appealId}/resolve' => ['post' => $op('Admin', 'Resolve an appeal', ['200' => $ok($envelope($ref('Appeal')))], ['parameters' => [$pathParam('appealId', 'Appeal id')]] + $body(['decision' => ['enum' => ['APPROVE', 'REJECT']], 'verdict' => ['enum' => ['CLEAR', 'SUSPICIOUS']], 'notes' => $str()], ['decision']))],
    '/admin/security/links/{linkId}' => ['patch' => $op('Admin', 'Set a manual security verdict on a canonical link', ['200' => $message], ['parameters' => [['name' => 'linkId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]]] + $body(['verdict' => ['enum' => ['CLEAR', 'SUSPICIOUS', 'MALICIOUS']], 'notes' => $str()], ['verdict']))],
    '/admin/categories' => [
        'get' => $op('Admin', 'All categories', ['200' => $ok($envelope(['type' => 'array', 'items' => $ref('Category')]))]),
        'post' => $op('Admin', 'Create a category', ['201' => $ok($envelope($ref('Category')), 'Created')], $body(['slug' => $str(), 'name_ar' => $str(), 'name_en' => $str(), 'description_ar' => $str(), 'description_en' => $str(), 'parent' => $str(), 'sort_order' => ['type' => 'integer'], 'is_active' => ['type' => 'boolean']], ['slug', 'name_ar', 'name_en'])),
    ],
    '/admin/categories/{slug}' => [
        'patch' => $op('Admin', 'Update a category', ['200' => $ok($envelope($ref('Category')))], ['parameters' => [$pathParam('slug', 'Category slug', '')]] + $body(['slug' => $str(), 'name_ar' => $str(), 'name_en' => $str(), 'description_ar' => $str(), 'description_en' => $str(), 'parent' => $str(), 'sort_order' => ['type' => 'integer'], 'is_active' => ['type' => 'boolean']])),
        'delete' => $op('Admin', 'Deactivate a category', ['200' => $message], ['parameters' => [$pathParam('slug', 'Category slug', '')]]),
    ],
    '/admin/audit-logs' => ['get' => $op('Admin', 'Audit log', ['200' => $ok($list('AuditLog'))], ['parameters' => [$query('action', ['type' => 'string']), $query('target_type', ['type' => 'string']), $query('target_id', ['type' => 'string']), ...$pageParams]])],
];

$nullableStr = ['type' => ['string', 'null']];
$dt = ['type' => ['string', 'null'], 'format' => 'date-time'];
$categoryBrief = ['type' => ['object', 'null'], 'properties' => ['slug' => ['type' => 'string'], 'name' => ['type' => 'string'], 'name_ar' => ['type' => 'string'], 'name_en' => ['type' => 'string']]];
$publicLinkProps = [
    'public_id' => ['type' => 'string', 'format' => 'uuid'], 'internal_path' => ['type' => 'string', 'example' => '/l/0d9a…'], 'frontend_url' => ['type' => 'string'],
    'access_role' => ['enum' => ['owner', 'viewer', 'public']], 'title' => ['type' => 'string'], 'description' => $nullableStr, 'image_url' => $nullableStr,
    'favicon_url' => $nullableStr, 'site_name' => $nullableStr, 'hostname' => ['type' => 'string'], 'source_type' => $ref('SourceType'), 'language' => $nullableStr,
    'author' => $nullableStr, 'source_published_at' => $dt, 'category' => $categoryBrief,
    'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'source' => ['enum' => ['USER', 'AI', null]]]]],
    'ai' => ['type' => ['object', 'null'], 'description' => 'AI-generated suggestions (clearly labelled)', 'properties' => ['summary' => $nullableStr, 'detailed_summary' => $nullableStr, 'suggested_title' => $nullableStr, 'suggested_category' => $categoryBrief, 'suggested_tags' => ['type' => 'array', 'items' => ['type' => 'string']], 'generated' => ['const' => true]]],
    'url' => ['type' => ['string', 'null'], 'description' => 'Original URL; null when the policy forbids exposing it to this caller'],
    'open_url' => ['type' => ['string', 'null'], 'description' => 'Destination the client may open; null when blocked'],
    'security' => ['type' => 'object', 'properties' => ['status' => $ref('SecurityStatus'), 'can_open' => ['type' => 'boolean'], 'warning' => $nullableStr, 'checked_at' => $dt, 'verified' => ['type' => 'boolean', 'description' => 'true only for an unexpired CLEAR verdict'], 'can_share' => ['type' => 'boolean', 'description' => 'Whether this link may be shared under the current security policy'], 'scanning' => ['type' => 'string', 'enum' => ['COMING_SOON', 'AVAILABLE']]]],
    'owner' => ['oneOf' => [$ref('PublicUserSummary'), ['type' => 'null']]], 'published_at' => $dt,
];

$spec = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'LinkLibrary API',
        'version' => '1.0.0',
        'description' => "REST API for the LinkLibrary backend (temporary product name; branding is configurable).\n\n"
            ."Authentication: Sanctum SPA cookies (call GET /sanctum/csrf-cookie first and send X-XSRF-TOKEN) or bearer tokens.\n"
            ."All responses use the envelope {success, message, data, meta?}; errors use {success:false, message, errors, code}.\n"
            .'Send Accept-Language: ar|en for localized messages. Unauthorized access to private resources returns 404.',
    ],
    'servers' => [['url' => '/api/v1']],
    'security' => [['sanctumCookie' => []], ['bearerToken' => []]],
    'tags' => array_map(fn ($t) => ['name' => $t], ['Auth', 'Profile', 'Links', 'Security', 'Sharing', 'Collections', 'Taxonomy', 'Search', 'Notifications', 'Public', 'Admin']),
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'sanctumCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'laravel_session', 'description' => 'Session cookie for stateful SPA domains (plus XSRF-TOKEN / X-XSRF-TOKEN).'],
            'bearerToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum personal access token (prefix ll_).'],
        ],
        'responses' => [
            'Unauthenticated' => ['description' => 'Authentication required (code UNAUTHENTICATED)'] + $json($ref('Error')),
            'Forbidden' => ['description' => 'Forbidden (FORBIDDEN, EMAIL_NOT_VERIFIED, ACCOUNT_SUSPENDED, LINK_SECURITY_BLOCKED…)'] + $json($ref('Error')),
            'NotFound' => ['description' => 'Not found or not accessible (NOT_FOUND)'] + $json($ref('Error')),
            'ValidationError' => ['description' => 'Validation or domain rule failed (VALIDATION_ERROR, INVALID_URL, URL_NOT_ALLOWED, LINK_NOT_PUBLISHABLE, LINK_NOT_SHAREABLE…)'] + $json($ref('Error')),
            'Conflict' => ['description' => 'Conflict (ALREADY_SHARED, GRANT_CYCLE, ALREADY_OWNER, INVITATION_NOT_PENDING…)'] + $json($ref('Error')),
            'RateLimited' => ['description' => 'Too many requests (RATE_LIMITED); see Retry-After'] + $json($ref('Error')),
        ],
        'schemas' => [
            'Envelope' => ['type' => 'object', 'required' => ['success'], 'properties' => ['success' => ['const' => true], 'message' => $nullableStr, 'data' => ['description' => 'Response payload (any JSON value; null for message-only responses). Each operation narrows it with allOf.'], 'meta' => ['type' => 'object']]],
            'Error' => ['type' => 'object', 'required' => ['success', 'message', 'errors', 'code'], 'properties' => [
                'success' => ['const' => false], 'message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]], 'code' => ['type' => 'string'],
            ]],
            'PaginationMeta' => ['type' => 'object', 'properties' => ['pagination' => ['type' => 'object', 'properties' => [
                'current_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'has_more' => ['type' => 'boolean'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer'],
            ]]]],
            'Visibility' => ['enum' => ['PRIVATE', 'PUBLIC']],
            'SecurityStatus' => ['enum' => ['PENDING', 'CLEAR', 'SUSPICIOUS', 'MALICIOUS', 'UNKNOWN']],
            'ProcessingStatus' => ['enum' => ['PENDING', 'PROCESSING', 'COMPLETED', 'PARTIAL', 'FAILED', 'BLOCKED']],
            'SourceType' => ['enum' => ['WEBSITE', 'ARTICLE', 'VIDEO', 'PLAYLIST', 'PDF', 'REPOSITORY', 'IMAGE', 'AUDIO', 'DOCUMENT', 'OTHER']],
            'User' => ['type' => 'object', 'properties' => [
                'public_id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string'], 'username' => ['type' => 'string'], 'email' => ['type' => 'string'],
                'email_verified' => ['type' => 'boolean'], 'email_verified_at' => $dt, 'avatar_url' => $nullableStr, 'bio' => $nullableStr,
                'locale' => ['enum' => ['ar', 'en']], 'theme' => ['enum' => ['system', 'light', 'dark']], 'profile_public' => ['type' => 'boolean'], 'is_admin' => ['type' => 'boolean'], 'created_at' => $dt,
            ]],
            'AuthResult' => ['type' => 'object', 'properties' => ['user' => $ref('User'), 'auth_mode' => ['enum' => ['session', 'token']], 'token' => ['type' => 'string'], 'token_type' => ['const' => 'Bearer'], 'expires_at' => $dt]],
            'PublicUserSummary' => ['type' => 'object', 'properties' => ['username' => ['type' => 'string'], 'name' => ['type' => 'string'], 'avatar_url' => $nullableStr, 'profile_url' => ['type' => 'string']]],
            'PublicUser' => ['type' => 'object', 'properties' => ['username' => ['type' => 'string'], 'name' => ['type' => 'string'], 'avatar_url' => $nullableStr, 'bio' => $nullableStr, 'profile_url' => ['type' => 'string'], 'joined_at' => ['type' => 'string', 'format' => 'date'], 'stats' => ['type' => 'object', 'properties' => ['public_links' => ['type' => 'integer'], 'public_collections' => ['type' => 'integer']]]]],
            'Session' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'ip_address' => $nullableStr, 'user_agent' => $nullableStr, 'last_active_at' => $dt, 'current' => ['type' => 'boolean']]],
            'ApiToken' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'last_used_at' => $dt, 'expires_at' => $dt, 'created_at' => $dt, 'current' => ['type' => 'boolean']]],
            'Processing' => ['type' => 'object', 'properties' => ['status' => $ref('ProcessingStatus'), 'security' => $ref('SecurityStatus'), 'extraction' => ['type' => 'string'], 'extraction_error_code' => $nullableStr, 'ai' => ['type' => 'string'], 'ai_enabled' => ['type' => 'boolean'], 'ai_wait' => ['description' => 'Set while the free AI quota is exhausted; AI suggestions resume automatically at retry_at.', 'type' => ['object', 'null'], 'properties' => ['code' => ['type' => 'string'], 'retry_at' => ['type' => 'string', 'format' => 'date-time']]]]],
            'PublicSavedLink' => ['type' => 'object', 'properties' => $publicLinkProps],
            'SavedLink' => ['description' => 'Owner representation adds visibility, notes, overrides, extracted metadata, processing and permissions. Viewers additionally get shared_by and permissions.', 'allOf' => [
                $ref('PublicSavedLink'),
                ['type' => 'object', 'properties' => [
                    'visibility' => $ref('Visibility'), 'allow_resharing' => ['type' => 'boolean'], 'is_favorite' => ['type' => 'boolean'], 'notes' => $nullableStr,
                    'overrides' => ['type' => 'object'], 'extracted' => ['type' => 'object'], 'processing_status' => $ref('ProcessingStatus'), 'processing' => $ref('Processing'),
                    'shared_by' => $nullableStr,
                    'permissions' => ['type' => 'object', 'properties' => ['can_edit' => ['type' => 'boolean'], 'can_delete' => ['type' => 'boolean'], 'can_change_visibility' => ['type' => 'boolean'], 'can_publish' => ['type' => 'boolean'], 'can_share' => ['type' => 'boolean'], 'can_reshare' => ['type' => 'boolean']]],
                    'created_at' => $dt, 'updated_at' => $dt, 'deleted_at' => $dt,
                ]],
            ]],
            'Collection' => ['type' => 'object', 'properties' => [
                'public_id' => ['type' => 'string', 'format' => 'uuid'], 'frontend_url' => ['type' => 'string'], 'name' => ['type' => 'string'], 'description' => $nullableStr,
                'cover_image_url' => $nullableStr, 'items_count' => ['type' => ['integer', 'null']], 'owner' => ['oneOf' => [$ref('PublicUserSummary'), ['type' => 'null']]],
                'visibility' => $ref('Visibility'), 'sort_order' => ['type' => 'integer'], 'items' => ['type' => 'array', 'items' => $ref('SavedLink')], 'created_at' => $dt, 'updated_at' => $dt,
            ]],
            'Category' => ['type' => 'object', 'properties' => ['slug' => ['type' => 'string'], 'name' => ['type' => 'string'], 'name_ar' => ['type' => 'string'], 'name_en' => ['type' => 'string'], 'description' => $nullableStr, 'description_ar' => $nullableStr, 'description_en' => $nullableStr, 'parent_slug' => $nullableStr, 'sort_order' => ['type' => 'integer'], 'is_active' => ['type' => 'boolean', 'description' => 'admins only'], 'links_count' => ['type' => 'integer', 'description' => 'public endpoints only']]],
            'Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'links_count' => ['type' => 'integer']]],
            'Invitation' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'email' => ['type' => 'string'], 'status' => ['enum' => ['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED']], 'invited_by' => ['type' => 'object'], 'via_reshare' => ['type' => 'boolean'], 'expires_at' => $dt, 'accepted_at' => $dt, 'revoked_at' => $dt, 'created_at' => $dt]],
            'ReceivedInvitation' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'invited_by' => ['type' => 'object'], 'expires_at' => $dt, 'created_at' => $dt]],
            'Grant' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'], 'grantee' => ['type' => 'object'], 'grantor' => ['type' => 'object'], 'parent_grant_id' => $nullableStr,
                'depth' => ['type' => 'integer'], 'status' => ['enum' => ['ACTIVE', 'REVOKED']], 'effective' => ['type' => 'boolean'], 'revoke_reason' => $nullableStr, 'created_at' => $dt, 'revoked_at' => $dt,
            ]],
            'SecurityScan' => ['type' => 'object', 'properties' => ['trigger' => ['type' => 'string'], 'status' => ['type' => 'string'], 'verdict' => ['oneOf' => [$ref('SecurityStatus'), ['type' => 'null']]], 'providers' => ['type' => 'array', 'items' => ['type' => 'object']], 'error_code' => $nullableStr, 'created_at' => $dt, 'completed_at' => $dt]],
            'SecurityDetails' => ['type' => 'object', 'properties' => [
                'status' => $ref('SecurityStatus'), 'stored_status' => $ref('SecurityStatus'), 'source' => ['enum' => ['PROVIDER', 'MANUAL_REVIEW']], 'checked_at' => $dt, 'expires_at' => $dt,
                'can_publish' => ['type' => 'boolean'], 'can_share' => ['type' => 'boolean'], 'can_open' => ['type' => 'boolean'], 'scanning' => ['type' => 'string', 'enum' => ['COMING_SOON', 'AVAILABLE']], 'unverified_sharing' => ['type' => 'boolean', 'description' => 'true when the link circulates only because of the temporary unverified-sharing policy'], 'disclaimer' => ['type' => 'string'],
                'history' => ['type' => 'array', 'items' => $ref('SecurityScan')], 'appeals' => ['type' => 'array', 'items' => $ref('Appeal')],
            ]],
            'Appeal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'status' => ['enum' => ['PENDING', 'APPROVED', 'REJECTED']], 'security_status_at_submission' => $ref('SecurityStatus'), 'reason' => ['type' => 'string'], 'resolution_verdict' => $nullableStr, 'reviewer_notes' => $nullableStr, 'resolved_at' => $dt, 'created_at' => $dt]],
            'Notification' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'kind' => ['type' => 'string', 'examples' => ['sharing.invitation_received', 'sharing.invitation_accepted', 'sharing.access_revoked', 'security.warning', 'processing.failed', 'account.password_changed']], 'data' => ['type' => 'object'], 'read' => ['type' => 'boolean'], 'read_at' => $dt, 'created_at' => $dt]],
            'AuditLog' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'action' => ['type' => 'string'], 'actor' => $nullableStr, 'target_type' => $nullableStr, 'target_id' => $nullableStr, 'metadata' => ['type' => ['object', 'null']], 'created_at' => $dt]],
        ],
    ],
];

file_put_contents(__DIR__.'/../docs/openapi.yaml', "# Generated by scripts/build-openapi.php — do not edit by hand.\n".Yaml::dump($spec, 12, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
echo 'docs/openapi.yaml written ('.count($paths)." paths)\n";
