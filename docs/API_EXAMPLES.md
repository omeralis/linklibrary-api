# API examples (curl, token mode)

```bash
API=http://localhost:8000/api/v1

# Register (token mode because no stateful Origin is sent)
curl -s -X POST $API/auth/register -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Sara","username":"sara","email":"sara@example.com","password":"secret-pass-123","password_confirmation":"secret-pass-123","device_name":"cli"}'
TOKEN=...   # data.token

# Save a link (always PRIVATE; returns immediately)
curl -s -X POST $API/links -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"url":"https://laravel.com/docs/queues","tags":["laravel","queues"],"notes":"read later"}'
# → 201 {"success":true,"message":"Link saved successfully.","data":{"public_id":"…","visibility":"PRIVATE","processing_status":"PENDING",…}}

# Poll processing
curl -s $API/links/$ID/processing-status -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

# Manual metadata (wins over extraction and AI)
curl -s -X PATCH $API/links/$ID -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"custom_title":"Queues guide","category":"programming"}'

# Publish (requires verified e-mail and a CLEAR security verdict)
curl -s -X PATCH $API/links/$ID/visibility -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"visibility":"PUBLIC"}'

# Allow viewers to reshare, then invite someone
curl -s -X PATCH $API/links/$ID/sharing-settings -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"allow_resharing":true}'
curl -s -X POST  $API/links/$ID/invitations      -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"email":"omar@example.com"}'

# Recipient (signed in with the invited, verified address) accepts the token from the e-mail
curl -s -X POST $API/invitations/$INVITE_TOKEN/accept -H "Authorization: Bearer $RECIPIENT_TOKEN" -H 'Accept: application/json'

# Owner inspects and revokes access (dependent grants are revoked too)
curl -s $API/links/$ID/access -H "Authorization: Bearer $TOKEN"
curl -s -X DELETE $API/links/$ID/access/$GRANT_ID -H "Authorization: Bearer $TOKEN"

# Collections
curl -s -X POST $API/collections -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"name":"Reading list"}'
curl -s -X POST $API/collections/$COLLECTION/links -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "{\"links\":[\"$ID\"]}"

# Search (own links incl. notes / shared with me / both)
curl -s "$API/search?q=queues&scope=all" -H "Authorization: Bearer $TOKEN"

# Public discovery (no auth)
curl -s "$API/public/links?sort=popular&category=technology"
curl -s "$API/public/search?q=%D8%A7%D9%84%D8%B0%D9%83%D8%A7%D8%A1"     # الذكاء
curl -s "$API/public/users/sara"

# Security
curl -s $API/links/$ID/security -H "Authorization: Bearer $TOKEN"
curl -s -X POST $API/links/$ID/security/rescan -H "Authorization: Bearer $TOKEN"
curl -s -X POST $API/links/$ID/security/appeal -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"reason":"This is my company website and it is not malicious."}'

# Notifications
curl -s $API/notifications/unread-count -H "Authorization: Bearer $TOKEN"
curl -s -X POST $API/notifications/read-all -H "Authorization: Bearer $TOKEN"
```
