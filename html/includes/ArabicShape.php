<?php
// Shaping Arab minimal untuk GD: ubah huruf ke presentation forms agar tersambung.
declare(strict_types=1);

function arabic_shape(string $text): string {
    // buang harakat/tanda baca (combining marks) agar penyambungan huruf tidak terputus
    $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text);
    // Peta: huruf => [isolated, final, initial, medial] (presentation forms)
    $map = [
        'ا' => ["\u{FE8D}", "\u{FE8E}", "\u{FE8D}", "\u{FE8E}"],
        'ب' => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"],
        'ت' => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"],
        'ث' => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"],
        'ج' => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"],
        'ح' => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"],
        'خ' => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"],
        'د' => ["\u{FEA9}", "\u{FEAA}", "\u{FEA9}", "\u{FEAA}"],
        'ذ' => ["\u{FEAB}", "\u{FEAC}", "\u{FEAB}", "\u{FEAC}"],
        'ر' => ["\u{FEAD}", "\u{FEAE}", "\u{FEAD}", "\u{FEAE}"],
        'ز' => ["\u{FEAF}", "\u{FEB0}", "\u{FEAF}", "\u{FEB0}"],
        'س' => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"],
        'ش' => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"],
        'ص' => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"],
        'ض' => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"],
        'ط' => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"],
        'ظ' => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"],
        'ع' => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"],
        'غ' => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"],
        'ف' => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"],
        'ق' => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"],
        'ك' => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"],
        'ل' => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"],
        'م' => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"],
        'ن' => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"],
        'ه' => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"],
        'و' => ["\u{FEED}", "\u{FEEE}", "\u{FEED}", "\u{FEEE}"],
        'ي' => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"],
        'ة' => ["\u{FE93}", "\u{FE94}", "\u{FE93}", "\u{FE94}"],
        'ى' => ["\u{FEF0}", "\u{FEF0}", "\u{FEF0}", "\u{FEF0}"],
        'أ' => ["\u{FE83}", "\u{FE84}", "\u{FE83}", "\u{FE84}"],
        'إ' => ["\u{FE87}", "\u{FE88}", "\u{FE87}", "\u{FE88}"],
        'ؤ' => ["\u{FE85}", "\u{FE86}", "\u{FE85}", "\u{FE86}"],
        'ئ' => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"],
        'ء' => ["\u{FE80}", "\u{FE80}", "\u{FE80}", "\u{FE80}"],
        'آ' => ["\u{FE81}", "\u{FE82}", "\u{FE81}", "\u{FE82}"],
    ];
    // huruf yang tidak menyambung ke kiri (hanya isolated/final)
    $noLeft = ['ا','د','ذ','ر','ز','و','ة','ى','أ','إ','ؤ','ء','آ'];

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $chars[$i];
        if (!isset($map[$c])) { $out[] = $c; continue; }
        // lam-alef ligature
        if ($c === 'ل' && $i + 1 < $n && in_array($chars[$i+1], ['ا','أ','إ','آ'], true)) {
            $next = $chars[$i+1];
            $lig = ['ا'=>"\u{FEFB}", 'أ'=>"\u{FEF7}", 'إ'=>"\u{FEF9}", 'آ'=>"\u{FEF5}"][$next];
            // cek apakah ligature final atau isolated
            $prevConnects = $i > 0 && isset($map[$chars[$i-1]]) && !in_array($chars[$i-1], $noLeft, true);
            $out[] = $prevConnects ? str_replace("\u{FEFB}", "\u{FEFC}", $lig) : $lig;
            // handle FEFC vs FEFB: gunakan peta eksplisit
            $ligMap = ['ا'=>["\u{FEFB}","\u{FEFC}"], 'أ'=>["\u{FEF7}","\u{FEF8}"],
                       'إ'=>["\u{FEF9}","\u{FEFA}"], 'آ'=>["\u{FEF5}","\u{FEF6}"]];
            $out[count($out)-1] = $ligMap[$next][$prevConnects ? 1 : 0];
            $i++;
            continue;
        }
        $prev = $i > 0 ? $chars[$i-1] : null;
        $next = $i + 1 < $n ? $chars[$i+1] : null;
        $prevConnects = $prev && isset($map[$prev]) && !in_array($prev, $noLeft, true);
        $nextConnects = $next && isset($map[$next]) && !in_array($c, $noLeft, true);
        if ($prevConnects && $nextConnects) $form = 3;      // medial
        elseif ($prevConnects) $form = 1;                   // final
        elseif ($nextConnects) $form = 2;                    // initial
        else $form = 0;                                      // isolated
        $out[] = $map[$c][$form];
    }
    // reverse untuk RTL
    return implode('', array_reverse($out));
}
