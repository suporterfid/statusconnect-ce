{{-- Account emails (sign-up, approval, password reset): StatusConnect's own station sign, with the brand icon. --}}
@extends('mail.layout')

@php
    $appUrl = rtrim((string) config('app.url'), '/');
    $company = (string) config('legal.company_name');
    $companyId = (string) config('legal.company_id');
@endphp

@section('doc_title'){{ $appName }}@endsection

@section('band_title')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="padding-right:10px;vertical-align:middle;"><img src="{{ $appUrl }}/brand/icon-512.png" width="28" height="28" alt="" style="display:block;width:28px;height:28px;border:0;border-radius:6px;"></td>
            <td style="vertical-align:middle;font-family:Inter,'Noto Sans',Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;color:#FFFFFF;">{{ $appName }}</td>
        </tr>
    </table>
@endsection

@section('footer')
    @if ($company !== '')
        <p style="margin:0;">{{ $company }}@if ($companyId !== '') · CNPJ {{ $companyId }}@endif</p>
        <p style="margin:6px 0 0;"><a href="{{ $appUrl }}/termos" lang="pt-BR" style="color:#5F5F5F;">Termos de Uso</a> · <a href="{{ $appUrl }}/privacidade" lang="pt-BR" style="color:#5F5F5F;">Política de Privacidade</a></p>
    @else
        <p style="margin:0;"><a href="{{ $appUrl }}" style="color:#5F5F5F;">{{ $appName }}</a></p>
    @endif
@endsection
