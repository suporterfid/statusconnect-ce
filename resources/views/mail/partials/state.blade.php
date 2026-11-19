{{--
    Status strip for emails: a roundel plus the label. Shape carries the state too:
    ok = jade solid, down = crimson ring, check = blue ring (test/info). The label is
    body-coloured for contrast; the colour lives in the roundel and the left rule.
--}}
@php
    $font = "Inter,'Noto Sans',Arial,Helvetica,sans-serif";
    [$colour, $tint] = match ($state) {
        'ok' => ['#059669', '#F1FAF4'],
        'down' => ['#B91C1C', '#FFF1F0'],
        default => ['#1A6DC1', '#EEF5FC'],
    };
    $roundel = $state === 'ok' ? "background:{$colour};" : "border:3px solid {$colour};";
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;background:{{ $tint }};border-left:4px solid {{ $colour }};border-radius:0 4px 4px 0;">
    <tr>
        <td width="16" style="padding:12px 0 12px 14px;vertical-align:middle;">
            <div style="width:16px;height:16px;border-radius:50%;box-sizing:border-box;{{ $roundel }}font-size:0;line-height:0;">&nbsp;</div>
        </td>
        <td style="padding:12px 14px 12px 10px;vertical-align:middle;font-family:{{ $font }};font-size:18px;line-height:1.3;font-weight:700;color:#252525;">{{ $label }}</td>
    </tr>
</table>
