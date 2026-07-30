<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Core\View;
use Lib\Settings;

/**
 * Public QR verification landing page.
 *
 * Every generated PDF prints a document ID and a QR pointing here. Anyone
 * holding the paper can confirm it came from this system, WITHOUT signing in
 * and WITHOUT the page disclosing customer data - only the document type,
 * when it was issued and by which branch.
 */
final class VerifyController extends Controller
{
    protected bool $requiresAuth = false;

    public function show(array $args): void
    {
        $uid = strtoupper(trim((string) ($args['uid'] ?? '')));

        // Keep the lookup tight; a document id is short and alphanumeric.
        if ($uid === '' || preg_match('/^[A-Z0-9\-]{6,40}$/', $uid) !== 1) {
            $this->render(null, 'That document code is not in a valid format.');
            return;
        }

        $document = Database::first(
            'SELECT d.*, u.full_name AS generated_by_name
             FROM report_documents d
             LEFT JOIN users u ON u.id = d.generated_by
             WHERE d.doc_uid = ? LIMIT 1',
            [$uid]
        );

        if ($document === null) {
            $this->render(null, 'No document with this code was issued by this system.');
            return;
        }

        Database::run(
            'UPDATE report_documents SET verify_count = verify_count + 1, last_verified_at = NOW() WHERE id = ?',
            [(int) $document['id']]
        );

        // Enrich with just enough context to be useful, never with PII.
        $context = $this->context((string) $document['entity_type'], (string) $document['entity_id']);

        $this->render($document, null, $context);
    }

    /**
     * @param array<string,mixed>|null $document
     * @param array<string,string> $context
     */
    private function render(?array $document, ?string $error, array $context = []): void
    {
        Response::html(
            View::render('verify', [
                'pageTitle'    => 'Document verification',
                'document'     => $document,
                'error'        => $error,
                'context'      => $context,
                'organisation' => Settings::getString('company.organisation', ''),
                'appName'      => Settings::getString('company.app_name', 'LRMS'),
            ], ''),
            $document === null ? 404 : 200
        );
    }

    /**
     * Non-identifying context: branch and date only.
     *
     * @return array<string,string>
     */
    private function context(string $entityType, string $entityId): array
    {
        if (!ctype_digit($entityId)) {
            return [];
        }

        switch ($entityType) {
            case 'visit':
                $row = Database::first(
                    'SELECT v.visit_date, br.name AS branch_name, br.code AS branch_code
                     FROM visits v LEFT JOIN branches br ON br.id = v.branch_id
                     WHERE v.id = ? LIMIT 1',
                    [(int) $entityId]
                );
                return $row === null ? [] : [
                    'Visit date' => date('d-m-Y', strtotime((string) $row['visit_date'])),
                    'Branch'     => (string) ($row['branch_name'] ?? '-'),
                ];

            case 'recovery':
                $row = Database::first(
                    'SELECT r.receipt_number, r.collected_at, r.status, br.name AS branch_name
                     FROM recoveries r LEFT JOIN branches br ON br.id = r.branch_id
                     WHERE r.id = ? LIMIT 1',
                    [(int) $entityId]
                );
                return $row === null ? [] : [
                    'Receipt number' => (string) $row['receipt_number'],
                    'Collected on'   => date('d-m-Y', strtotime((string) $row['collected_at'])),
                    'Branch'         => (string) ($row['branch_name'] ?? '-'),
                    'Receipt status' => ucfirst((string) $row['status']),
                ];

            case 'loan':
                $row = Database::first(
                    'SELECT br.name AS branch_name FROM loans l
                     LEFT JOIN branches br ON br.id = l.branch_id WHERE l.id = ? LIMIT 1',
                    [(int) $entityId]
                );
                return $row === null ? [] : ['Branch' => (string) ($row['branch_name'] ?? '-')];

            default:
                return [];
        }
    }
}
