<?php
// Route table: [method, path (relative to the index.php directory), [Controller, method], options].
// Option 'csrf' => false: API endpoints without a session (/token, /mcp), protected by the client secret / Bearer token.
// To add a page: a new row here + a method in the controller + a view in views/.
declare(strict_types=1);

use App\Controllers\{AboutController, AuthController, ConnectionsController, GoogleController, HealthController, HelpController, HomeController, LogsController, McpController, OAuthController, PlansController, SettingsController, SetupController, UploadController};

$api = ['csrf' => false];

return [
    ['GET', '/', [HomeController::class, 'index']],
    ['GET', '/health', [HealthController::class, 'show']],   // uptime monitor, deploy smoke test
    ['POST', '/login', [AuthController::class, 'login']],
    ['POST', '/logout', [AuthController::class, 'logout']],
    ['GET', '/login/verify', [AuthController::class, 'confirm']],   // link from the e-mail: a page with a button
    ['POST', '/login/verify', [AuthController::class, 'verify']],   // button: consumes the token and logs in

    // Installer (works only until the installation is finished; see SetupController).
    ['GET', '/setup', [SetupController::class, 'index']],
    ['POST', '/setup/requirements', [SetupController::class, 'continueToAdmin']],
    ['POST', '/setup', [SetupController::class, 'store']],

    ['GET', '/settings', [SettingsController::class, 'index']],
    ['POST', '/settings/email', [SettingsController::class, 'saveEmail']],
    ['POST', '/settings/email/cancel', [SettingsController::class, 'cancelEmail']],
    ['POST', '/settings/smtp', [SettingsController::class, 'saveSmtp']],
    ['POST', '/settings/smtp/test', [SettingsController::class, 'testMail']],

    ['GET', '/logs', [LogsController::class, 'index']],   // read-only view of the newest log events (admin)
    ['GET', '/help', [HelpController::class, 'index']],   // user guide (admin)
    ['GET', '/about', [AboutController::class, 'index']], // author, disclaimer, version, licence (admin)

    ['GET', '/connections', [ConnectionsController::class, 'index']],
    ['POST', '/connections/add', [ConnectionsController::class, 'add']],
    ['POST', '/connections/delete', [ConnectionsController::class, 'delete']],
    ['POST', '/connections/revoke', [ConnectionsController::class, 'revoke']],
    ['POST', '/connections/renew', [ConnectionsController::class, 'renew']],

    ['GET', '/google/connect', [GoogleController::class, 'connect']],
    ['GET', '/oauth/google/callback', [GoogleController::class, 'callback']],
    ['POST', '/google/disconnect', [GoogleController::class, 'disconnect']],

    ['GET', '/upload', [UploadController::class, 'index']],

    // Plan preview (24 h link token) and approval (link token + admin session + plan hash; see PlansController).
    ['GET', '/plan', [PlansController::class, 'show']],
    ['POST', '/plan/approve', [PlansController::class, 'approve'], $api],
    ['GET', '/plan/img', [PlansController::class, 'image']],
    ['POST', '/upload', [UploadController::class, 'store']],
    ['GET', '/upload/youtube', [UploadController::class, 'index']], // link from upload_link: the same page
    ['POST', '/upload/youtube/list', [UploadController::class, 'youtubeList']],
    ['POST', '/upload/youtube/select', [UploadController::class, 'youtubeSelect']],
    ['GET', '/upload/thumb', [UploadController::class, 'thumb']],

    ['GET', '/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']],
    ['GET', '/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']],
    ['GET', '/.well-known/oauth-authorization-server', [OAuthController::class, 'serverMetadata']],
    ['GET', '/.well-known/openid-configuration', [OAuthController::class, 'serverMetadata']],
    ['GET', '/authorize', [OAuthController::class, 'authorize']],
    ['POST', '/token', [OAuthController::class, 'token'], $api],

    ['POST', '/mcp', [McpController::class, 'handle'], $api],
    ['GET', '/mcp', [McpController::class, 'methodNotAllowed']],
];
