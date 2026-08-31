<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;

/**
 * Today's items (handoff Section 4.2) and the unsubscribe route
 * (Section 12).
 *
 * The items themselves are read on the main menu; what lives here is
 * dismissing one, and the tokenised opt-out that the email's
 * List-Unsubscribe header points at.
 */
final class DigestController extends Controller
{
    /**
     * Dismiss one of today's items. It stays in the table -- it is a record
     * of what the model said and when -- but it stops being shown and will
     * not be sent again.
     */
    public function dismiss(Request $request): Response
    {
        $id = $request->intInput('reminder_id');
        if ($id !== null) {
            $this->reminders()->dismiss($id);
        }
        return $this->back($request, '/');
    }

    /**
     * The tokenised opt-out (handoff Section 12).
     *
     * Public, because a person clicking a link in an email is not signed in
     * and must not be made to sign in to stop the mail -- that is exactly the
     * pattern that gets a sender marked as spam.
     *
     * The token is looked up by its unique index and never echoed back into
     * the page except into this page's own form actions.
     */
    public function unsubscribe(Request $request, array $params): Response
    {
        $user = $this->accounts()->findByUnsubscribeToken((string) $params['token']);
        if ($user === null) {
            // Not 403: a wrong token gives nothing away about whether it is
            // a real one (hosting Section 6.3's reasoning, applied here).
            throw HttpException::notFound('That unsubscribe link is not valid.');
        }

        return $this->render('unsubscribe', [
            'name'    => (string) $user['name'],
            'email'   => (string) $user['email'],
            'token'   => (string) $params['token'],
            'enabled' => (int) $user['email_digest_enabled'] === 1,
            'done'    => false,
        ]);
    }

    /**
     * The POST half. RFC 8058 One-Click: a mail client may POST here with no
     * session, no CSRF token and no confirmation page, which is what Gmail
     * and Outlook now expect of bulk mail -- so this route is Route::TOKEN_ACCESS,
     * which is exempt from the CSRF check, and the token in the path is the
     * only credential.
     *
     * That is safe because the only thing it can do is turn someone's own
     * email off. The worst an attacker with a token achieves is what the
     * token is for.
     */
    public function confirmUnsubscribe(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $user = $this->accounts()->findByUnsubscribeToken($token);
        if ($user === null) {
            throw HttpException::notFound('That unsubscribe link is not valid.');
        }

        $this->accounts()->setDigestEnabled((int) $user['id'], false);

        // A One-Click client sends List-Unsubscribe=One-Click and wants a
        // bare 200, not a page.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return Response::text("Unsubscribed.\n");
        }

        return $this->render('unsubscribe', [
            'name'    => (string) $user['name'],
            'email'   => (string) $user['email'],
            'token'   => $token,
            'enabled' => false,
            'done'    => true,
        ]);
    }

    /** Turning them back on, from the same page. */
    public function resubscribe(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $user = $this->accounts()->findByUnsubscribeToken($token);
        if ($user === null) {
            throw HttpException::notFound('That link is not valid.');
        }

        $this->accounts()->setDigestEnabled((int) $user['id'], true);

        return $this->render('unsubscribe', [
            'name'    => (string) $user['name'],
            'email'   => (string) $user['email'],
            'token'   => $token,
            'enabled' => true,
            'done'    => true,
        ]);
    }
}
