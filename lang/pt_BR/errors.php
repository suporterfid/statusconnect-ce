<?php

return [
    'code' => 'Erro :code',
    'home' => 'Ir para a página inicial',
    'retry' => 'Tentar de novo',
    'route_label' => 'Linha interrompida',
    '401' => ['title' => 'Entre para continuar', 'text' => 'Esta página é só para quem está conectado. Entre com sua conta e tente de novo.'],
    '403' => ['title' => 'Acesso não permitido', 'text' => 'Sua conta não tem permissão para ver esta página. Se acha que deveria ter, fale com quem administra a sua organização.'],
    '404' => ['title' => 'Página não encontrada', 'text' => 'O endereço pode ter mudado ou estar digitado errado. Confira o link ou volte à página inicial.'],
    '419' => ['title' => 'A página expirou', 'text' => 'O formulário ficou aberto por muito tempo. Recarregue a página e envie de novo.'],
    '429' => ['title' => 'Muitas tentativas seguidas', 'text' => 'Recebemos pedidos demais em pouco tempo. Aguarde um minuto e tente de novo.'],
    '500' => ['title' => 'Algo deu errado do nosso lado', 'text' => 'Um erro inesperado interrompeu este pedido. Já ficou registrado. Tente de novo em instantes.'],
    '503' => ['title' => 'Serviço em manutenção', 'text' => 'Estamos fazendo uma manutenção rápida. Tente de novo em alguns minutos.'],
    '4xx' => ['title' => 'Não foi possível atender este pedido', 'text' => 'Algo no pedido impediu a resposta. Volte à página inicial e tente de novo.'],
    '5xx' => ['title' => 'Serviço indisponível no momento', 'text' => 'Um problema do nosso lado interrompeu este pedido. Tente de novo em instantes.'],
];
