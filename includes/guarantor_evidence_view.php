
<section id="guarantor-evidence-review" data-reviewer-id="<?= htmlspecialchars($evidenceStaff ? (string) $_SESSION['SESS_MEMBER_ID'] : '', ENT_QUOTES, 'UTF-8') ?>" data-member="<?= $evidenceStaff ? 'false' : 'true' ?>" class="evidence-review" aria-labelledby="evidence-heading">
    <h1 id="evidence-heading">Guarantor signed evidence and staff review</h1>
    <div class="evidence-note" role="note"><strong>Signed guarantor approval must be uploaded and accepted by staff before loan approval.</strong><p>An online response alone is insufficient. Acknowledgement files are optional supporting evidence and cannot replace signed approval. A borrower or staff upload does not authenticate the guarantor's identity or accept the evidence.</p><p>Only the latest signed approval for the current invitation and loan terms can qualify. A newer pending, quarantined or rejected version prevents an older accepted document from qualifying. This screen never approves or disburses the loan.</p></div>
    <noscript><p role="alert">JavaScript is required to load associations, upload files and submit staff review. No evidence is saved by this page without it.</p></noscript>
    <section class="evidence-card" aria-labelledby="association-heading">
        <h2 id="association-heading">Confirm the loan and guarantor invitation</h2>
        <form id="evidence-association-form">
            <label for="evidence-loan-id">Loan request reference (required)</label><input id="evidence-loan-id" required inputmode="numeric" pattern="[1-9][0-9]{0,9}" value="<?= htmlspecialchars($initialLoan, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="association-help">
            <p id="association-help" class="evidence-help">Use the selected loan reference. The server loads its actual guarantor invitations before an upload is available.</p>
            <div class="evidence-actions"><button type="submit">Load guarantor invitations</button></div>
        </form>
        <p id="evidence-loan-context"></p>
        <label for="evidence-invitation">Guarantor invitation (required to upload or review)</label><select id="evidence-invitation" disabled aria-describedby="association-help"><option value="" selected>Load a loan and choose its guarantor</option></select>
        <p id="evidence-association-status" class="evidence-status" role="status" aria-live="polite" tabindex="-1"></p>
    </section>
    <section id="evidence-upload-section" class="evidence-card" hidden aria-labelledby="upload-heading">
        <h2 id="upload-heading">Upload a signed document</h2><p id="evidence-invitation-context"></p>
        <form id="evidence-upload-form" enctype="multipart/form-data">
            <label for="evidence-kind">Evidence category (required)</label><select id="evidence-kind" name="evidence_kind" required><option value="" selected>Choose the evidence category</option><option value="approval">Signed guarantor approval — required for approval</option><option value="acknowledgement">Acknowledgement — optional supporting evidence</option></select>
            <label for="evidence-file">PDF, JPEG or PNG document (required)</label><input id="evidence-file" name="evidence" type="file" required accept="application/pdf,image/jpeg,image/png" aria-describedby="upload-help">
            <p id="upload-help" class="evidence-help">Maximum file size: 5 MiB (5,242,880 bytes). The server checks actual file type and size, stores the document privately, and binds it to the current loan terms and selected invitation. Missing or incomplete malware scanning keeps it quarantined.</p>
            <div class="evidence-actions"><button type="submit">Upload evidence for staff review</button><button type="button" id="evidence-new-upload">Start a different upload</button></div>
            <p class="evidence-help">Uploads create new evidence versions. If a result is uncertain, reload history before uploading again; another upload may supersede an earlier accepted version. This tab saves an opaque upload reference and file fingerprint for safe retry after a refresh. Sign in and reselect the same file to retry it. HTTPS and available tab storage are required; no password, login token, filename or file content is saved there.</p>
            <p id="evidence-upload-status" class="evidence-status" role="status" aria-live="polite" tabindex="-1"></p>
        </form>
    </section>
    <section id="evidence-history-section" class="evidence-card" hidden aria-labelledby="history-heading">
        <h2 id="history-heading">Private evidence history and review</h2><p class="evidence-help">Downloads require authorization and a clean malware scan. Acceptance also requires current terms, the latest version and the server's staff review checks. Rejection requires a reason. Unavailable actions remain blocked by the server.</p>
        <div class="evidence-actions"><button id="evidence-reload" type="button">Reload evidence history</button></div>
        <p id="evidence-history-status" class="evidence-status" role="status" aria-live="polite" tabindex="-1"></p><ol id="evidence-history" aria-label="Guarantor evidence versions"></ol><div class="evidence-actions"><button id="evidence-older" type="button" disabled>Load older evidence versions</button></div>
    </section>
    <?php if ($evidenceStaff): ?><p><a href="loan-request-admin.php">Return to loan administration</a></p><?php endif; ?>
</section>
