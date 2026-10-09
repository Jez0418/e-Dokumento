<?php
declare(strict_types=1);

// Every request state change goes through a database function, which re-checks
// the caller's role and the allowed transition. PHP only maps the button to it.
if (!is_post()) {
    redirect('/requests');
}
$db = Supabase::user();
$id = post('id');
$action = post('action');
$reason = post('reason');
$back = safe_next(post('_back')) ?? url('requests/view', ['id' => $id]);
if (!is_uuid($id)) {
    abort(404);
}

$roleFor = [
    'start_review'   => ['secretary'],
    'to_payment'     => ['secretary'],
    'to_processing'  => ['secretary'],
    'to_approval'    => ['secretary'],
    'issue'          => ['secretary'],
    'release'        => ['secretary', 'admin'],
    'reject'         => ['secretary', 'captain'],
    'approve'        => ['captain'],
    'cancel'         => ['resident', 'secretary'],
    'accept_file'    => ['secretary'],
    'reject_file'    => ['secretary'],
    'upload_file'    => ['resident', 'secretary'],
    'record_payment' => ['treasurer'],
    'void_payment'   => ['treasurer'],
];
if (!isset($roleFor[$action])) {
    abort(400, 'Unknown action.');
}
require_role(...$roleFor[$action]);

try {
    switch ($action) {
        case 'start_review':
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'under_review']);
            flash_success('Review started.');
            break;
        case 'to_payment':
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'for_payment']);
            flash_success('Sent to the Treasurer for payment.');
            break;
        case 'to_processing':
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'processing']);
            flash_success('Moved to processing.');
            break;
        case 'to_approval':
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'for_approval']);
            flash_success('Sent to the Punong Barangay for approval.');
            break;
        case 'issue':
            $r = $db->rpc('issue_document', ['p_request_id' => $id]);
            flash_success('Document ' . ($r['document_no'] ?? '') . ' issued. It is ready to print and release.');
            break;
        case 'approve':
            $r = $db->rpc('issue_document', ['p_request_id' => $id]);
            flash_success('Approved. Document ' . ($r['document_no'] ?? '') . ' is ready for release.');
            break;
        case 'release':
            $to = post('released_to');
            if (mb_strlen($to) < 2 || mb_strlen($to) > 120 || preg_match('/[<>]/', $to)) {
                flash_error('Enter the name of the person who claimed the document.');
                redirect($back);
            }
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'released', 'p_released_to' => $to]);
            flash_success('Marked as released to ' . $to . '.');
            break;
        case 'reject':
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                flash_error('Give a reason of 10 to 500 characters.');
                redirect($back);
            }
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'rejected', 'p_remarks' => $reason]);
            flash_success('Request rejected. The resident was notified.');
            break;
        case 'cancel':
            $db->rpc('transition_request', ['p_request_id' => $id, 'p_to_status' => 'cancelled']);
            flash_success('Request cancelled.');
            break;
        case 'accept_file':
        case 'reject_file':
            $att = post('attachment_id');
            if (!is_uuid($att)) {
                abort(400);
            }
            if ($action === 'reject_file' && (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
                flash_error('Give a reason of 10 to 500 characters.');
                redirect($back);
            }
            $db->rpc('review_attachment', [
                'p_attachment_id' => $att,
                'p_decision'      => $action === 'accept_file' ? 'accepted' : 'rejected',
                'p_remarks'       => $action === 'reject_file' ? $reason : null,
            ]);
            flash_success($action === 'accept_file' ? 'File accepted.' : 'File rejected. The resident was asked for a replacement.');
            break;
        case 'upload_file':
            $reqId = post('requirement_id');
            $file = Upload::check($_FILES['file'] ?? null, 'The file', true);
            if (is_string($file) || !preg_match('/^\d{1,5}$/', $reqId)) {
                flash_error(is_string($file) ? $file : 'Choose a requirement.');
                redirect($back);
            }
            $owner = $db->first('document_requests', [['id', 'eq.' . $id], ['select', 'resident_id']]);
            if (!$owner) {
                abort(404);
            }
            $path = Upload::store($db, 'request-files', $owner['resident_id'], $file);
            try {
                $db->rpc('add_request_attachment', [
                    'p_request_id'     => $id,
                    'p_requirement_id' => (int) $reqId,
                    'p_path'           => $path,
                    'p_name'           => $file['name'],
                    'p_mime'           => $file['mime'],
                    'p_size'           => $file['size'],
                ]);
            } catch (Throwable $e) {
                $db->removeObjects('request-files', [$path]);
                throw $e;
            }
            flash_success('File uploaded. The Secretary will review it.');
            break;
        case 'record_payment':
            $v = new Validator($_POST);
            $or = $v->text('or_number', 'OR number', true, 1, 30);
            $method = $v->in('method', 'payment method', array_keys(PAYMENT_METHODS));
            $amount = $v->decimal('amount', 'Amount', true, 0.01);
            $ref = $method !== 'cash' ? $v->text('reference_no', 'Reference number', true, 3, 60) : null;
            if ($or !== null && !preg_match('/^[A-Za-z0-9\-]{1,30}$/', $or)) {
                $v->error('or_number', 'OR number can only contain letters, digits and hyphens.');
            }
            if ($v->fails()) {
                flash_error(implode(' ', $v->errors()));
                redirect($back);
            }
            $db->rpc('record_payment', [
                'p_request_id'   => $id,
                'p_or_number'    => $or,
                'p_amount'       => (float) $amount,
                'p_method'       => $method,
                'p_reference_no' => $ref,
            ]);
            flash_success('Payment recorded under OR ' . strtoupper((string) $or) . '. The request moved to Processing.');
            break;
        case 'void_payment':
            $pid = post('payment_id');
            if (!is_uuid($pid)) {
                abort(400);
            }
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                flash_error('Give a reason of 10 to 500 characters.');
                redirect($back);
            }
            $db->rpc('void_payment', ['p_payment_id' => $pid, 'p_reason' => $reason]);
            flash_success('Payment voided. The request is back at For payment.');
            break;
    }
} catch (Throwable $e) {
    flash_error(db_error($e));
}
redirect($back);
