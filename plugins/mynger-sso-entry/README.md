# Mynger SSO entry

Mynger opens this site as `https://<site>/<page>?mynger_sso=1`. If the visitor isn't signed in to
WordPress, the plugin starts the existing "Sign in with Mynger" OpenID Connect flow and brings them back
to `<page>`. Someone already signed in to Mynger goes straight through (the OAuth client is first-party),
so they land on the page signed in without typing anything. Without the parameter, nothing changes.

Requires the OpenID Connect Generic Client plugin, already configured for Mynger.

## Deploy

1. Test it locally first: the Bonakude repo's `tools/wp-local/README.md` (same plugin, same steps).
2. Upload the `mynger-sso-entry` folder to `wp-content/plugins/` over SFTP or the Hostinger File Manager
   (not the wp-admin plugin editor), then activate it under Plugins.
3. Check `https://<site>/?mynger_sso=1` in a browser signed in to mynger.com.

It adds a new folder and replaces nothing. To roll back, delete `wp-content/plugins/mynger-sso-entry`
over SFTP; that works even if wp-admin is down.
