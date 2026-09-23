# Board nomination form

Add `[spp_board_nomination]` to a WordPress page (a Divi Text module in Text mode works). Deploy `functions.php`, `inc/spp-board-nomination.php`, and `js/spp-board-nomination.js` together.

The form and submission handler require login. It collects the fields from the Board Nominee Information Form, with at least one phone number required. The signature is the authenticated account's username, and the submission timestamp is generated on the server in Toronto time. Neither can be supplied by the browser.

The complete form is sent as an HTML email to `board@pickleballstouffville.ca`; it is in the email body, not a Word/PDF attachment. Replies go to the submitting member. Nomination details are not stored in a separate website archive. The board mailbox is the submission record.

There is no annual configuration or automatic deadline. The page states that nominations must be submitted at least ten days before the AGM; the board checks the recorded date.

Keep this page excluded from full-page/CDN caching because it contains a member identity and nonce. The shortcode also signals WordPress not to cache it. Test on staging with a logged-out visit, a logged-in submission, and mobile layout. Verify actual receipt in the board mailbox: WordPress accepting mail for sending does not confirm inbox delivery. Use an explicitly labelled test nomination for that check.

Local checks: `php -l inc/spp-board-nomination.php`, `php tests/spp-board-nomination-test.php`, and `node --check js/spp-board-nomination.js`. The standalone test stubs mail and never sends messages.
