<?php

/**
 * BUSINESS CORRESPONDENT (BC) FIELD VISIT REPORT — printable Hindi form.
 *
 * A one-for-one reproduction of the Central Bank of India paper form, filled
 * from the visit the agent submitted. Section numbers below match the printed
 * original, including its jump from 5 to 7.
 *
 * @var array<string,mixed>       $visit
 * @var ?string                   $mobile         decrypted borrower mobile
 * @var ?string                   $contactMobile  decrypted number actually reached
 * @var list<array<string,mixed>> $photos
 * @var string                    $organisation
 */

use App\Core\View;
use App\Services\PhotoStorageService;

/** Prints a value, or a blank dotted line when there is nothing. */
$val = static function ($v): string {
    $v = is_string($v) ? trim($v) : $v;
    return ($v === null || $v === '' ) ? '<span class="v empty"></span>' : '<span class="v">' . View::e((string) $v) . '</span>';
};

/** A tick box. $on decides whether the tick is drawn. */
$box = static function (bool $on, string $label): string {
    return '<span class="opt"><span class="box' . ($on ? ' on' : '') . '"></span>'
        . '<span>' . View::e($label) . '</span></span>';
};

/** dd/mm/yyyy, or an empty dotted line. */
$date = static function ($raw) use ($val): string {
    if ($raw === null || $raw === '' || str_starts_with((string) $raw, '0000')) {
        return $val(null);
    }
    $ts = strtotime((string) $raw);
    return $val($ts === false ? (string) $raw : date('d/m/Y', $ts));
};

$money = static function ($raw) use ($val): string {
    return $val($raw === null ? null : number_format((float) $raw, 2));
};

/** Was this multi-select code ticked? */
$has = static function (?string $csv, string $code): bool {
    if ($csv === null || $csv === '') {
        return false;
    }
    return in_array($code, array_map('trim', explode(',', $csv)), true);
};

$reasons = $visit['nonpayment_reasons'] ?? null;
$recos   = $visit['recommendations'] ?? null;

// Section 12 ticks follow from what the agent actually attached.
$photoTypes = [];
foreach ($photos as $p) {
    $photoTypes[(string) $p['photo_type']] = true;
}

?>
<div class="sheet">

    <div class="doc-head">
        <div class="bank"><?= View::e($organisation) ?></div>
        <div class="branchline">
            शाखा : <strong><?= View::e(($visit['branch_name'] ?? '') !== '' ? $visit['branch_name'] : '—') ?></strong>
            <?php if (($visit['branch_code'] ?? '') !== ''): ?>
                (<?= View::e($visit['branch_code']) ?>)
            <?php endif; ?>
        </div>
    </div>

    <div class="title-bar">BUSINESS CORRESPONDENT (BC) FIELD VISIT REPORT</div>
    <div class="title-sub">(ऋण खाता सत्यापन एवं वसूली भ्रमण रिपोर्ट)</div>

    <!-- ============================================ 1. सामान्य विवरण -->
    <div class="sec">
        <h2>1. सामान्य विवरण</h2>
        <div class="grid">
            <div class="f"><span class="k">विजिट दिनांक :</span><?= $date($visit['visit_date'] ?? null) ?></div>
            <div class="f"><span class="k">BC Code :</span><?= $val($visit['bc_code'] ?? null) ?></div>
            <div class="f"><span class="k">BC Agent का नाम :</span><?= $val($visit['agent_name'] ?? null) ?></div>
            <div class="f"><span class="k">लिंक शाखा :</span><?= $val($visit['branch_name'] ?? null) ?></div>
            <div class="f wide"><span class="k">ग्राम / स्थान :</span><?= $val(implode(', ', array_filter([
                $visit['village'] ?? null,
                $visit['panchayat'] ?? null,
                $visit['block'] ?? null,
                $visit['district'] ?? null,
            ]))) ?></div>
        </div>
    </div>

    <!-- ======================================= 2. उधारकर्ता का विवरण -->
    <div class="sec">
        <h2>2. उधारकर्ता का विवरण</h2>
        <div class="grid">
            <div class="f wide"><span class="k">उधारकर्ता का नाम :</span><?= $val($visit['full_name'] ?? null) ?></div>
            <div class="f wide"><span class="k">पिता / पति का नाम :</span><?= $val($visit['guardian_name'] ?? null) ?></div>
            <div class="f wide"><span class="k">पता :</span><?= $val(implode(', ', array_filter([
                $visit['address_line'] ?? null,
                $visit['village'] ?? null,
                $visit['district'] ?? null,
                $visit['state'] ?? null,
                $visit['pincode'] ?? null,
            ]))) ?></div>
            <div class="f"><span class="k">मोबाइल नम्बर :</span><?= $val($mobile) ?></div>
            <div class="f"><span class="k">आधार संख्या :</span><?= $val(
                ($visit['aadhaar_last4'] ?? null) ? 'XXXX XXXX ' . $visit['aadhaar_last4'] : null
            ) ?></div>
        </div>
    </div>

    <!-- ======================================= 3. ऋण खाते का विवरण -->
    <div class="sec">
        <h2>3. ऋण खाते का विवरण</h2>
        <div class="grid">
            <div class="f wide"><span class="k">Loan Account Number :</span><?= $val($visit['account_number'] ?? null) ?></div>
        </div>
        <div class="subhead">Loan Type</div>
        <div class="opts">
            <?= $box(($visit['loan_type'] ?? '') === 'ckcc', 'CKCC') ?>
            <?= $box(($visit['loan_type'] ?? '') === 'agl', 'AGL') ?>
            <?= $box(($visit['loan_type'] ?? '') === 'dairy', 'Dairy') ?>
            <?= $box(($visit['loan_type'] ?? '') === 'shg', 'SHG') ?>
            <?= $box(($visit['loan_type'] ?? '') === 'other', 'Other') ?>
            <span class="opt"><?= $val($visit['loan_type_other'] ?? null) ?></span>
        </div>
        <div class="grid" style="margin-top:4px">
            <div class="f"><span class="k">Outstanding Amount (₹) :</span><?= $money($visit['outstanding_amount'] ?? null) ?></div>
            <div class="f"><span class="k">Overdue Amount (₹) :</span><?= $money($visit['overdue_amount'] ?? null) ?></div>
            <div class="f wide"><span class="k">NPA Since :</span><?= $date($visit['npa_date'] ?? null) ?></div>
        </div>
    </div>

    <!-- ==================================== 4. खाते की वर्तमान स्थिति -->
    <div class="sec">
        <h2>4. खाते की वर्तमान स्थिति</h2>
        <div class="opts">
            <?= $box(($visit['account_status'] ?? '') === 'npa', 'NPA') ?>
            <?= $box(($visit['account_status'] ?? '') === 'ckcc_od2', 'CKCC OD-2') ?>
            <?= $box(($visit['account_status'] ?? '') === 'krm_ots', 'KRM OTS') ?>
        </div>
        <div class="grid" style="margin-top:4px">
            <div class="f"><span class="k">NPA DATE :</span><?= $date($visit['npa_date'] ?? null) ?></div>
        </div>
        <div class="opts">
            <?= $box(!empty($visit['rc_issued']), 'RC Issued') ?>
            <?= $box(($visit['account_status'] ?? '') === 'other', 'Other') ?>
            <span class="opt"><?= $val($visit['account_status_other'] ?? null) ?></span>
        </div>
    </div>

    <!-- =================================== 5. ग्राहक से संपर्क की स्थिति -->
    <div class="sec">
        <h2>5. ग्राहक से संपर्क की स्थिति</h2>
        <div class="opts" style="flex-direction:column;gap:2px">
            <?= $box(($visit['contact_status'] ?? '') === 'borrower', 'स्वयं उधारकर्ता से मुलाकात हुई') ?>
            <?= $box(($visit['contact_status'] ?? '') === 'family', 'परिवार के सदस्य से मुलाकात हुई') ?>
        </div>
        <div class="grid" style="margin-top:3px">
            <div class="f"><span class="k">परिवार के सदस्य का नाम :</span><?= $val($visit['met_person'] ?? null) ?></div>
            <div class="f"><span class="k">संबंध :</span><?= $val($visit['met_relation'] ?? null) ?></div>
        </div>
        <div class="opts">
            <?= $box(($visit['contact_status'] ?? '') === 'not_found', 'ग्राहक घर पर नहीं मिला') ?>
            <?= $box(($visit['contact_status'] ?? '') === 'phone', 'फोन पर संपर्क हुआ') ?>
            <?= $box(($visit['contact_status'] ?? '') === 'phone_off', 'फोन बंद मिला') ?>
        </div>
        <div class="grid">
            <div class="f wide"><span class="k">मोबाइल नंबर :</span><?= $val($contactMobile ?? $mobile) ?></div>
        </div>
    </div>

    <!-- ======================================= 7. भौतिक सत्यापन -->
    <div class="sec">
        <h2>7. भौतिक सत्यापन (Physical Verification)</h2>

        <div class="subhead">उधारकर्ता जीवित है</div>
        <div class="opts">
            <?= $box(($visit['borrower_alive'] ?? null) !== null && (int) $visit['borrower_alive'] === 1, 'हाँ') ?>
            <?= $box(($visit['borrower_alive'] ?? null) !== null && (int) $visit['borrower_alive'] === 0, 'नहीं') ?>
        </div>

        <div class="subhead">वर्तमान निवास</div>
        <div class="opts">
            <?= $box(($visit['residence_status'] ?? '') === 'same', 'उसी पते पर रह रहे हैं') ?>
            <?= $box(($visit['residence_status'] ?? '') === 'moved', 'अन्य स्थान पर चले गए') ?>
        </div>

        <div class="subhead">व्यवसाय / आय का स्रोत</div>
        <div class="opts">
            <?= $box(($visit['income_source'] ?? '') === 'agri', 'कृषि') ?>
            <?= $box(($visit['income_source'] ?? '') === 'dairy', 'डेयरी') ?>
            <?= $box(($visit['income_source'] ?? '') === 'job', 'नौकरी') ?>
            <?= $box(($visit['income_source'] ?? '') === 'business', 'व्यापार') ?>
            <?= $box(($visit['income_source'] ?? '') === 'labour', 'मजदूरी') ?>
            <?= $box(($visit['income_source'] ?? '') === 'other', 'अन्य') ?>
            <span class="opt"><?= $val($visit['income_source_other'] ?? null) ?></span>
        </div>
    </div>

    <!-- page 2 of the printed original starts here -->
    <div class="page-break"></div>

    <!-- ============================ 8. ग्राहक का कथन / विजिट का विवरण -->
    <div class="sec">
        <h2>8. ग्राहक का कथन / विजिट का विवरण</h2>
        <div class="statement"><?= View::e((string) ($visit['remarks'] ?? '')) ?></div>
    </div>

    <!-- ==================================== 9. वसूली की संभावना -->
    <div class="sec">
        <h2>9. वसूली की संभावना</h2>
        <div class="subhead">क्या ग्राहक भुगतान करने के लिए सहमत है?</div>
        <div class="opts">
            <?= $box(($visit['willing_to_pay'] ?? null) !== null && (int) $visit['willing_to_pay'] === 1, 'हाँ') ?>
            <?= $box(($visit['willing_to_pay'] ?? null) !== null && (int) $visit['willing_to_pay'] === 0, 'नहीं') ?>
        </div>
        <div class="opts">
            <?= $box(($visit['payment_plan'] ?? '') === 'interest', 'ब्याज जमा करेगा') ?>
            <?= $box(($visit['payment_plan'] ?? '') === 'krm_ots', 'KRM OTS के अंतर्गत भुगतान करेगा') ?>
        </div>
        <div class="grid" style="margin-top:4px">
            <div class="f"><span class="k">आश्वासित भुगतान राशि (₹) :</span><?= $money(
                ((float) ($visit['promise_amount'] ?? 0)) > 0 ? $visit['promise_amount'] : null
            ) ?></div>
            <div class="f"><span class="k">संभावित भुगतान तिथि :</span><?= $date($visit['promise_date'] ?? null) ?></div>
        </div>
    </div>

    <!-- ============================== 10. भुगतान न करने का कारण -->
    <div class="sec">
        <h2>10. भुगतान न करने का कारण</h2>
        <div class="opts">
            <?= $box($has($reasons, 'financial'), 'आर्थिक समस्या') ?>
            <?= $box($has($reasons, 'crop_failure'), 'फसल खराब') ?>
            <?= $box($has($reasons, 'cattle_loss'), 'पशु हानि') ?>
            <?= $box($has($reasons, 'illness'), 'बीमारी') ?>
            <?= $box($has($reasons, 'unemployment'), 'बेरोजगारी') ?>
            <?= $box($has($reasons, 'dispute'), 'विवाद') ?>
            <?= $box($has($reasons, 'other_bank_loan'), 'अन्य बैंक ऋण') ?>
            <?= $box($has($reasons, 'other'), 'अन्य') ?>
            <span class="opt"><?= $val($visit['nonpayment_other'] ?? null) ?></span>
        </div>
    </div>

    <!-- ======================= 11. BC Agent की टिप्पणी / अनुशंसा -->
    <div class="sec">
        <h2>11. BC Agent की टिप्पणी / अनुशंसा</h2>
        <div class="opts" style="flex-direction:column;gap:2px">
            <?= $box($has($recos, 'recovery_good'), 'Recovery की संभावना अच्छी है।') ?>
            <?= $box($has($recos, 'followup_needed'), 'नियमित Follow-up आवश्यक है।') ?>
            <?= $box($has($recos, 'legal_action'), 'कानूनी कार्यवाही हेतु विचार किया जाए।') ?>
            <?= $box($has($recos, 'rc_issue'), 'RC जारी करने की कार्यवाही की जाए।') ?>
            <?= $box($has($recos, 'krm_ots'), 'KRM OTS योजना के अंतर्गत निस्तारण कराया जा सकता है।') ?>
            <?= $box($has($recos, 'other'), 'अन्य') ?>
        </div>
        <?php if (($visit['recommendation'] ?? '') !== ''): ?>
            <div class="grid"><div class="f wide"><?= $val($visit['recommendation']) ?></div></div>
        <?php endif; ?>
    </div>

    <!-- ================================== 12. संलग्न साक्ष्य -->
    <div class="sec">
        <h2>12. संलग्न साक्ष्य</h2>
        <div class="opts">
            <?= $box(isset($photoTypes['customer']), 'ग्राहक के साथ फोटो') ?>
            <?= $box(isset($photoTypes['house']), 'घर का फोटो') ?>
            <?= $box(true, 'GPS लोकेशन') ?>
            <?= $box(isset($photoTypes['document']), 'आधार की प्रति') ?>
            <?= $box(isset($photoTypes['other']) || isset($photoTypes['selfie']), 'अन्य') ?>
        </div>

        <div class="gps-line">
            GPS : <?= View::e(number_format((float) $visit['latitude'], 6)) ?>,
            <?= View::e(number_format((float) $visit['longitude'], 6)) ?>
            <?php if (($visit['accuracy_m'] ?? null) !== null): ?>
                (± <?= View::e((string) round((float) $visit['accuracy_m'])) ?> मी)
            <?php endif; ?>
            <?php if (($visit['distance_from_customer_m'] ?? null) !== null): ?>
                &nbsp;|&nbsp; ग्राहक के दर्ज स्थान से दूरी :
                <?= View::e((string) (int) $visit['distance_from_customer_m']) ?> मी
            <?php endif; ?>
        </div>

        <?php if ($photos !== []): ?>
            <div class="shots" style="margin-top:6px">
                <?php
                $captions = [
                    'customer' => 'ग्राहक के साथ',
                    'house'    => 'घर का फोटो',
                    'document' => 'दस्तावेज़',
                    'selfie'   => 'सेल्फी',
                    'other'    => 'अन्य',
                ];
                foreach ($photos as $p):
                    $src = PhotoStorageService::url($p['file_path']);
                    if ($src === null) {
                        continue;
                    }
                    ?>
                    <div class="shot">
                        <img src="<?= View::e($src) ?>" alt="">
                        <div class="cap"><?= View::e($captions[(string) $p['photo_type']] ?? 'फोटो') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ======================================== 13. घोषणा -->
    <div class="sec">
        <h2>13. घोषणा</h2>
        <div class="declare">
            मैं प्रमाणित करता हूँ कि उपरोक्त विवरण मेरे द्वारा दिनांक
            <strong><?= View::e(date('d/m/Y', strtotime((string) $visit['visit_date']) ?: time())) ?></strong>
            को स्थल पर जाकर किए गए वास्तविक निरीक्षण एवं सत्यापन के आधार पर तैयार किया गया है
            तथा इसमें अंकित जानकारी मेरे सर्वोत्तम ज्ञान एवं विश्वास के अनुसार सही है।
        </div>

        <div class="signs">
            <div class="sign-box">
                <div class="lbl">उधारकर्ता के हस्ताक्षर / अंगूठा निशान</div>
                <?php $bs = PhotoStorageService::url($visit['borrower_signature_path'] ?? null); ?>
                <?php if ($bs !== null): ?>
                    <img src="<?= View::e($bs) ?>" alt="">
                <?php else: ?>
                    <div class="blank"></div>
                <?php endif; ?>
            </div>
            <div class="sign-box">
                <div class="lbl">BC Agent के हस्ताक्षर</div>
                <?php $as = PhotoStorageService::url($visit['signature_path'] ?? null); ?>
                <?php if ($as !== null): ?>
                    <img src="<?= View::e($as) ?>" alt="">
                <?php else: ?>
                    <div class="blank"></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid" style="margin-top:6px">
            <div class="f"><span class="k">दिनांक :</span><?= $date($visit['visit_date'] ?? null) ?></div>
        </div>
    </div>

    <div class="foot">
        सत्यापन कोड : <?= View::e((string) $visit['visit_uid']) ?>
        &nbsp;|&nbsp; रिपोर्ट जनरेट : <?= View::e(date('d/m/Y H:i')) ?>
    </div>
</div>
