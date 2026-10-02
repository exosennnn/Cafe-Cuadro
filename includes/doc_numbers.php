<?php
/**
 * DOCUMENT NUMBER SEQUENCES
 *
 * Used by the Employment Contract No. and the Request Forms module.
 *
 * Why this exists alongside the older generateDocNumber() in functions.php:
 *
 *   1. generateDocNumber() calls $pdo->beginTransaction() itself, so it throws
 *      if the caller is already inside a transaction. Contract numbers have to
 *      be issued inside the existing "create employee record" transaction in
 *      modules/hr_staff/applications.php, so the helper must be nestable.
 *   2. It reads the `doc_sequences` table, which has no primary key and already
 *      contains duplicate (doc_type, year) rows, making SELECT ... FOR UPDATE
 *      unreliable there. `document_sequences` has a proper composite PK.
 *
 * generateDocNumber() and `doc_sequences` are left completely untouched so the
 * Procurement module keeps behaving exactly as before.
 */

if (!function_exists('nextDocumentNumber')) {
    /**
     * Reserve and return the next number for a document type.
     *
     * Safe to call whether or not a transaction is already open: if one is, the
     * reservation joins it (and rolls back with it); if not, a short one is
     * opened just for the reservation.
     *
     * @param string $docType Sequence key, e.g. 'EC' or 'REQ-LV'.
     * @param string $prefix  Display prefix, e.g. 'EC' -> "EC-2026-0001".
     * @return string
     */
    function nextDocumentNumber(PDO $pdo, string $docType, string $prefix): string
    {
        $year = (int)date('Y');
        $ownTransaction = !$pdo->inTransaction();

        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Make sure the row exists before locking it. INSERT IGNORE is a
            // no-op when another request created it a moment earlier.
            $pdo->prepare("INSERT IGNORE INTO document_sequences (doc_type, year, last_number) VALUES (?, ?, 0)")
                ->execute([$docType, $year]);

            $locked = $pdo->prepare("SELECT last_number FROM document_sequences WHERE doc_type = ? AND year = ? FOR UPDATE");
            $locked->execute([$docType, $year]);
            $next = (int)$locked->fetchColumn() + 1;

            $pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE doc_type = ? AND year = ?")
                ->execute([$next, $docType, $year]);

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return sprintf('%s-%d-%04d', $prefix, $year, $next);
    }
}

if (!function_exists('signatureHash')) {
    /**
     * Short, non-secret verification code shown beside an e-signature, so a
     * printed copy of a document can be checked against the database record.
     * It is derived from the signing facts, not from anything confidential.
     */
    function signatureHash(string $documentNo, string $actor, string $action, string $timestamp): string
    {
        return strtoupper(substr(hash('sha256', $documentNo . '|' . $actor . '|' . $action . '|' . $timestamp), 0, 16));
    }
}
