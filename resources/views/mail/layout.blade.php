{{--
    Base for every HTML email (DESIGN.md): a station-sign band on top, a white card
    on the warm canvas, and a quiet footer. Email-safe: tables, inline styles, no SVG,
    no web fonts required. Child layouts set band_title, and may set band_bg / band_text
    (a tenant's accent colour), band_meta, body and footer.
--}}
@php
    $font = "Inter,'Noto Sans',Arial,Helvetica,sans-serif";
    $bandBg = trim($__env->yieldContent('band_bg')) ?: '#252525';
    $bandText = trim($__env->yieldContent('band_text')) ?: '#FFFFFF';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@hasSection('doc_title')@yield('doc_title')@else{{ strip_tags(trim($__env->yieldContent('band_title'))) }}@endif</title>
</head>
<body style="margin:0;padding:0;background:#F7F6F3;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F7F6F3;">
    <tr>
        <td align="center" style="padding:24px 12px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#FFFFFF;border:1px solid #D9D7D3;border-radius:6px;border-collapse:separate;">
                <tr>
                    <td style="background:{{ $bandBg }};color:{{ $bandText }};padding:18px 24px;border-radius:5px 5px 0 0;font-family:{{ $font }};font-size:18px;line-height:1.3;font-weight:700;">
                        @yield('band_title')
                        @hasSection('band_meta')
                            <div style="margin-top:2px;font-size:13px;font-weight:400;color:{{ $bandText }};opacity:.8;">@yield('band_meta')</div>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;font-family:{{ $font }};font-size:16px;line-height:1.5;color:#252525;">
                        @yield('body')
                    </td>
                </tr>
            </table>
            @hasSection('footer')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:16px 24px 0;font-family:{{ $font }};font-size:13px;line-height:1.5;color:#5F5F5F;text-align:center;">
                            @yield('footer')
                        </td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
</table>
</body>
</html>
