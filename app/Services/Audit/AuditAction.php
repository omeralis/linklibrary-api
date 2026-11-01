<?php

namespace App\Services\Audit;

final class AuditAction
{
    public const USER_REGISTERED = 'user.registered';

    public const USER_LOGIN = 'user.login';

    public const USER_LOGIN_FAILED = 'user.login_failed';

    public const USER_LOGOUT = 'user.logout';

    public const EMAIL_VERIFIED = 'user.email_verified';

    public const EMAIL_CHANGED = 'user.email_changed';

    public const PASSWORD_CHANGED = 'user.password_changed';

    public const PASSWORD_RESET = 'user.password_reset';

    public const ACCOUNT_DELETED = 'user.account_deleted';

    public const SESSION_REVOKED = 'user.session_revoked';

    public const TOKEN_REVOKED = 'user.token_revoked';

    public const LINK_CREATED = 'link.created';

    public const LINK_UPDATED = 'link.updated';

    public const LINK_DELETED = 'link.deleted';

    public const LINK_RESTORED = 'link.restored';

    public const VISIBILITY_CHANGED = 'link.visibility_changed';

    public const SHARING_SETTINGS_CHANGED = 'link.sharing_settings_changed';

    public const INVITATION_SENT = 'sharing.invitation_sent';

    public const INVITATION_ACCEPTED = 'sharing.invitation_accepted';

    public const INVITATION_REVOKED = 'sharing.invitation_revoked';

    public const GRANT_CREATED = 'sharing.grant_created';

    public const GRANT_REVOKED = 'sharing.grant_revoked';

    public const SECURITY_STATUS_CHANGED = 'security.status_changed';

    public const SECURITY_RESCAN_REQUESTED = 'security.rescan_requested';

    public const APPEAL_SUBMITTED = 'security.appeal_submitted';

    public const APPEAL_RESOLVED = 'security.appeal_resolved';

    public const SECURITY_MANUAL_VERDICT = 'security.manual_verdict';

    public const COLLECTION_VISIBILITY_CHANGED = 'collection.visibility_changed';

    public const CATEGORY_CHANGED = 'admin.category_changed';
}
