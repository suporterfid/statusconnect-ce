{{-- Primary action button for emails: action blue, 44px tall, works without CSS support for padding on links. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;">
    <tr>
        <td style="background:#1A6DC1;border-radius:4px;">
            <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;font-family:Inter,'Noto Sans',Arial,Helvetica,sans-serif;font-size:16px;line-height:20px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:4px;">{{ $label }}</a>
        </td>
    </tr>
</table>
