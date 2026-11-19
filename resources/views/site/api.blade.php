@extends('site.layout')

{{-- Public API reference (#151), rendered from resources/api/openapi.json. No scripts: navigation is plain anchors. --}}
@php
    $sections = $api->sections();
    $operationCount = array_sum(array_map(fn ($s) => count($s['operations']), $sections));
@endphp

@section('title', 'Referência da API · StatusConnect')

@section('styles')
    .api-head { background: var(--color-bg-surface); border-block-end: 1px solid var(--color-border-default); padding-block: 40px 32px; }
    .api-head h1 { font-size: clamp(2rem, 5vw, 2.75rem); line-height: 1.15; letter-spacing: -0.02em; }
    .api-head .lead { margin: 12px 0 0; font-size: 1.125rem; color: var(--color-text-secondary); max-inline-size: 70ch; }
    .api-meta { display: flex; flex-wrap: wrap; gap: 8px 12px; margin-block-start: 20px; align-items: center; }
    .api-meta .chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 12px; border-radius: 999px; box-shadow: inset 0 0 0 1px var(--color-border-strong); font-size: .875rem; }
    .api-meta .chip code { font-size: .8125rem; }

    .api-body { display: grid; gap: 32px; padding-block: 40px; }
    @media (min-width: 1024px) { .api-body { grid-template-columns: 248px minmax(0, 1fr); gap: 48px; } }

    /* Index drawn as a metro line, like the operator app's sidebar. */
    .api-nav { font-size: .9375rem; }
    @media (min-width: 1024px) { .api-nav { position: sticky; inset-block-start: 24px; align-self: start; max-block-size: calc(100vh - 48px); overflow-y: auto; } }
    .api-nav ol { list-style: none; margin: 0; padding: 0; position: relative; }
    .api-nav > ol::before { content: ""; position: absolute; inset-inline-start: 5px; inset-block: 14px; inline-size: 4px; border-radius: 2px; background: var(--color-border-default); }
    .api-nav > ol > li > a { position: relative; display: flex; align-items: center; gap: 12px; min-block-size: 36px; color: var(--color-text-primary); text-decoration: none; font-weight: 600; }
    .api-nav > ol > li > a::before { content: ""; flex: none; inline-size: 14px; block-size: 14px; border-radius: 50%; background: var(--color-bg-canvas); box-shadow: inset 0 0 0 3px var(--line-ink); position: relative; z-index: 1; }
    .api-nav > ol > li > a:hover { text-decoration: underline; }
    .api-nav ol ol { padding-inline-start: 26px; margin-block-end: 6px; }
    .api-nav ol ol a { display: block; padding-block: 3px; color: var(--color-text-secondary); text-decoration: none; font-size: .875rem; }
    .api-nav ol ol a:hover { color: var(--color-text-primary); text-decoration: underline; }
    @media (max-width: 1023px) { .api-nav ol ol { display: none; } .api-nav > ol { columns: 2; } .api-nav > ol::before { display: none; } .api-nav > ol > li > a::before { display: none; } }

    .api-content { min-inline-size: 0; }
    .api-content > section { scroll-margin-block-start: 24px; }
    .api-content > section + section { margin-block-start: 56px; }
    .api-content h2 { font-size: clamp(1.5rem, 3vw, 1.875rem); letter-spacing: -0.02em; display: flex; align-items: center; gap: 12px; }
    .api-content h2 .line-no { inline-size: 30px; block-size: 30px; font-size: .875rem; }
    .api-content .section-lead { margin: 8px 0 0; color: var(--color-text-secondary); max-inline-size: 70ch; }
    .prose p { max-inline-size: 72ch; margin: 12px 0 0; }
    .prose code, .op code, .api-table code { font-family: "IBM Plex Mono", Menlo, Consolas, monospace; font-size: .875em; }
    .prose p code, .op-desc code, .api-table td code { padding: 1px 5px; border-radius: 4px; background: var(--color-bg-surface); border: 1px solid var(--color-border-default); }

    .op { margin-block-start: 24px; border: 1px solid var(--color-border-default); border-radius: 6px; background: var(--color-bg-elevated); scroll-margin-block-start: 24px; }
    .op-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 14px 18px; border-block-end: 1px solid var(--color-border-default); }
    .method { flex: none; display: inline-grid; place-items: center; min-inline-size: 64px; padding: 3px 8px; border-radius: 4px; background: var(--sign-bg); color: var(--sign-text); font: 700 .75rem/1.4 "IBM Plex Mono", Menlo, Consolas, monospace; letter-spacing: .04em; }
    .method.is-delete { background: transparent; color: var(--color-text-primary); box-shadow: inset 0 0 0 2px var(--status-down); }
    .op-path { font: 600 .9375rem/1.4 "IBM Plex Mono", Menlo, Consolas, monospace; overflow-wrap: anywhere; }
    .op-path .param { color: var(--color-text-secondary); }
    .op-body { padding: 16px 18px 18px; }
    .op h3 { font-size: 1.125rem; }
    .op-desc { margin: 6px 0 0; max-inline-size: 75ch; }
    .op-tags { display: flex; flex-wrap: wrap; gap: 6px 10px; margin-block-start: 12px; font-size: .8125rem; color: var(--color-text-secondary); }
    .op-tags span { padding: 2px 10px; border-radius: 999px; box-shadow: inset 0 0 0 1px var(--color-border-default); }
    .op h4 { margin: 20px 0 8px; font-size: .9375rem; }

    .api-table-wrap { overflow-x: auto; border: 1px solid var(--color-border-default); border-radius: 6px; }
    .api-table { border-collapse: collapse; inline-size: 100%; font-size: .875rem; line-height: 1.5; }
    .api-table th { background: var(--color-bg-surface); text-align: start; font-weight: 600; padding: 8px 12px; border-block-end: 1px solid var(--color-border-default); white-space: nowrap; }
    .api-table td { padding: 8px 12px; vertical-align: top; border-block-start: 1px solid var(--color-border-default); }
    .api-table tr:first-child td { border-block-start: 0; }
    .api-table .name { font-family: "IBM Plex Mono", Menlo, Consolas, monospace; font-size: .8125rem; white-space: nowrap; }
    .api-table .type { font-family: "IBM Plex Mono", Menlo, Consolas, monospace; font-size: .8125rem; color: var(--color-text-secondary); white-space: nowrap; }
    .api-table .req { font-size: .75rem; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; }
    .api-table .cons { display: block; margin-block-start: 2px; color: var(--color-text-secondary); font-size: .8125rem; }

    .codes { display: grid; gap: 12px; }
    @media (min-width: 1200px) { .codes.two { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
    .code { margin: 0; }
    .code figcaption { font-size: .8125rem; font-weight: 600; color: var(--color-text-secondary); margin-block-end: 6px; }
    .code pre { margin: 0; padding: 12px 14px; border-radius: 6px; background: var(--sign-bg); color: #F1F1EF; font: .8125rem/1.55 "IBM Plex Mono", Menlo, Consolas, monospace; overflow-x: auto; max-block-size: 420px; }

    .responses { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; font-size: .875rem; }
    .responses li { display: flex; gap: 10px; align-items: baseline; }
    .responses .status { flex: none; font: 600 .8125rem/1.4 "IBM Plex Mono", Menlo, Consolas, monospace; min-inline-size: 36px; }
    .responses .status.ok { color: var(--color-success-fg); }
    .responses .status.err { color: var(--color-danger-fg); }

    .basics { display: grid; gap: 16px; margin-block-start: 16px; }
    @media (min-width: 900px) { .basics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .basics .card { padding: 18px 20px; border: 1px solid var(--color-border-default); border-radius: 6px; background: var(--color-bg-elevated); }
    .basics h3 { font-size: 1.0625rem; }
    .basics p { margin: 8px 0 0; font-size: .9375rem; }
@endsection

@section('content')
    <header class="api-head">
        <div class="wrap">
            <h1>Referência da API</h1>
            <p class="lead">{!! \App\Http\Views\ApiReference::inline($api->description()) !!}</p>
            <div class="api-meta">
                <span class="chip">URL base <code>{{ $api->baseUrl() }}</code></span>
                <span class="chip">Versão <code>{{ $api->version() }}</code></span>
                <span class="chip">{{ $operationCount }} operações</span>
                <a class="btn btn-quiet" href="{{ url('/api/openapi.json') }}">Baixar OpenAPI (JSON)</a>
            </div>
        </div>
    </header>

    <div class="wrap api-body">
        <nav class="api-nav" aria-label="Seções da API">
            <ol>
                <li><a href="#primeiros-passos">Primeiros passos</a></li>
                @foreach ($sections as $section)
                    <li>
                        <a href="#{{ $section['slug'] }}">{{ $section['name'] }}</a>
                        <ol>
                            @foreach ($section['operations'] as $op)
                                <li><a href="#{{ $op['id'] }}">{{ $op['summary'] }}</a></li>
                            @endforeach
                        </ol>
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="api-content">
            <section id="primeiros-passos" aria-labelledby="primeiros-passos-title">
                <h2 id="primeiros-passos-title">Primeiros passos</h2>
                <div class="basics">
                    <div class="card prose">
                        <h3>Autenticação</h3>
                        @foreach (preg_split('/\n\s*\n/', trim($api->securityDescription())) ?: [] as $paragraph)
                            <p>{!! \App\Http\Views\ApiReference::inline($paragraph) !!}</p>
                        @endforeach
                    </div>
                    <div class="card prose">
                        <h3>Formato</h3>
                        <p>Pedidos e respostas usam JSON em UTF-8. Envie <code>Content-Type: application/json</code> no corpo e <code>Accept: application/json</code> para receber erros em JSON.</p>
                        <p>Os caminhos levam o identificador da organização (<code>tenantId</code>) e do ambiente (<code>environmentId</code>). Os dois aparecem em <code>GET /v1/me/memberships</code> e no endereço do painel.</p>
                    </div>
                    <div class="card prose">
                        <h3>Erros</h3>
                        <p><code>401</code> chave ausente ou inválida; <code>403</code> sem permissão para a ação ou limite do plano; <code>404</code> recurso inexistente ou de outra organização; <code>422</code> dados inválidos, com a lista de campos em <code>errors</code>; <code>429</code> limite de requisições atingido.</p>
                    </div>
                    <div class="card prose">
                        <h3>Papéis</h3>
                        <p>Cada operação indica os papéis de membro que podem usá-la. Uma chave de API age em nome da organização, dentro do ambiente em que foi criada.</p>
                    </div>
                </div>
            </section>

            @foreach ($sections as $n => $section)
                <section id="{{ $section['slug'] }}" aria-labelledby="{{ $section['slug'] }}-title">
                    <h2 id="{{ $section['slug'] }}-title"><span class="line-no" aria-hidden="true">{{ $n + 1 }}</span>{{ $section['name'] }}</h2>
                    @if ($section['description'] !== '')
                        <p class="section-lead">{{ $section['description'] }}</p>
                    @endif

                    @foreach ($section['operations'] as $op)
                        <article class="op" id="{{ $op['id'] }}" aria-labelledby="{{ $op['id'] }}-title">
                            <div class="op-head">
                                <span class="method {{ $op['method'] === 'DELETE' ? 'is-delete' : '' }}">{{ $op['method'] }}</span>
                                <span class="op-path">{!! preg_replace('/\{([A-Za-z]+)\}/', '<span class="param">{$1}</span>', e($op['path'])) !!}</span>
                            </div>
                            <div class="op-body">
                                <h3 id="{{ $op['id'] }}-title">{{ $op['summary'] }}</h3>
                                @if ($op['description'] !== '')
                                    <p class="op-desc">{!! \App\Http\Views\ApiReference::inline($op['description']) !!}</p>
                                @endif
                                <div class="op-tags">
                                    @if ($op['public'])
                                        <span>Sem chave de API</span>
                                    @elseif ($op['roles'] !== [])
                                        <span>Papéis: {{ implode(', ', $op['roles']) }}</span>
                                    @endif
                                    @if ($op['apiKeyPermission'] === false)
                                        <span>Só com sessão</span>
                                    @elseif ($op['apiKeyPermission'])
                                        <span>Chave: <code>{{ $op['apiKeyPermission'] }}</code></span>
                                    @endif
                                    @if ($op['rateLimit'])
                                        <span>Limite: {{ $op['rateLimit'] }}</span>
                                    @endif
                                </div>

                                @if ($op['params'] !== [])
                                    <h4>Parâmetros</h4>
                                    <div class="api-table-wrap">
                                        <table class="api-table">
                                            <thead><tr><th scope="col">Nome</th><th scope="col">Onde</th><th scope="col">Tipo</th><th scope="col">Descrição</th></tr></thead>
                                            <tbody>
                                                @foreach ($op['params'] as $param)
                                                    <tr>
                                                        <td class="name">{{ $param['name'] }}@if ($param['required'])<br><span class="req">obrigatório</span>@endif</td>
                                                        <td>{{ ['path' => 'caminho', 'query' => 'consulta', 'header' => 'cabeçalho'][$param['in']] ?? $param['in'] }}</td>
                                                        <td class="type">{{ $param['type'] }}</td>
                                                        <td>{!! \App\Http\Views\ApiReference::inline($param['description']) !!}@foreach ($param['constraints'] as $c)<span class="cons">{{ $c }}</span>@endforeach</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                                @if ($op['body'] && $op['body']['fields'] !== [])
                                    <h4>Corpo <code>{{ $op['body']['mediaType'] }}</code></h4>
                                    <div class="api-table-wrap">
                                        <table class="api-table">
                                            <thead><tr><th scope="col">Campo</th><th scope="col">Tipo</th><th scope="col">Descrição</th></tr></thead>
                                            <tbody>
                                                @foreach ($op['body']['fields'] as $field)
                                                    <tr>
                                                        <td class="name">{{ $field['name'] }}@if ($field['required'])<br><span class="req">obrigatório</span>@endif</td>
                                                        <td class="type">{{ $field['type'] }}</td>
                                                        <td>{!! \App\Http\Views\ApiReference::inline($field['description']) !!}@foreach ($field['constraints'] as $c)<span class="cons">{{ $c }}</span>@endforeach</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @elseif ($op['body'])
                                    <h4>Corpo <code>{{ $op['body']['mediaType'] }}</code></h4>
                                    @if ($op['body']['description'] !== '')
                                        <p class="op-desc">{!! \App\Http\Views\ApiReference::inline($op['body']['description']) !!}</p>
                                    @endif
                                @endif

                                <h4>Exemplo</h4>
                                <div class="codes {{ $op['success'] && $op['success']['example'] ? 'two' : '' }}">
                                    <figure class="code">
                                        <figcaption>Pedido</figcaption>
                                        <pre><code>{{ $op['curl'] }}</code></pre>
                                    </figure>
                                    @if ($op['success'] && $op['success']['example'])
                                        <figure class="code">
                                            <figcaption>Resposta {{ $op['success']['code'] }}</figcaption>
                                            <pre><code>{{ $op['success']['example'] }}</code></pre>
                                        </figure>
                                    @endif
                                </div>

                                @if ($op['responses'] !== [])
                                    <h4>Respostas</h4>
                                    <ul class="responses">
                                        @foreach ($op['responses'] as $response)
                                            <li><span class="status {{ $response['code'][0] === '2' ? 'ok' : ($response['code'][0] >= '4' ? 'err' : '') }}">{{ $response['code'] }}</span><span>{!! \App\Http\Views\ApiReference::inline($response['description']) !!}</span></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </section>
            @endforeach
        </div>
    </div>
@endsection
