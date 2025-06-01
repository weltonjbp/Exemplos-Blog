<?php // ### api.php (REVISTO E MELHORADO) ###
require_once 'conexao.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Permite acesso de qualquer origem (para desenvolvimento)
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Resposta pré-validação para OPTIONS (necessário para alguns cenários CORS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$acao = $_REQUEST['acao'] ?? null; // Usar _REQUEST para pegar de GET ou POST
$input = json_decode(file_get_contents('php://input'), true);

// Logging básico para depuração
error_log("API Request - Acao: $acao, Method: ".$_SERVER['REQUEST_METHOD'].", Input: ".print_r($input, true));

function responder($dados, $status = 200) {
    http_response_code($status);
    echo json_encode($dados);
    exit;
}

function validarCampos($camposRequeridos, $dados) {
    foreach ($camposRequeridos as $campo) {
        if (!isset($dados[$campo]) || (is_string($dados[$campo]) && trim($dados[$campo]) === '')) {
            return "Campo '$campo' é obrigatório e não pode ser vazio.";
        }
    }
    return null;
}


try {
    switch ($acao) {
        case 'carregar_dados_iniciais':
            $mesasStmt = $pdo->query("SELECT id, numero, status, capacidade FROM mesas ORDER BY numero ASC");
            $mesas = $mesasStmt->fetchAll();

            $cardapioStmt = $pdo->query("SELECT id, nome, categoria, preco, descricao FROM cardapio ORDER BY categoria, nome ASC");
            $cardapio = $cardapioStmt->fetchAll();

            $categoriasStmt = $pdo->query("SELECT DISTINCT categoria FROM cardapio ORDER BY categoria ASC");
            $categorias = $categoriasStmt->fetchAll(PDO::FETCH_COLUMN);

            responder(['success' => true, 'mesas' => $mesas, 'cardapio' => $cardapio, 'categorias' => $categorias]);
            break;

        case 'carregar_pedido_mesa':
            $mesa_id = filter_var($_GET['mesa_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$mesa_id) {
                responder(['success' => false, 'error' => 'ID da mesa inválido.'], 400);
            }

            $pedidoStmt = $pdo->prepare("SELECT * FROM pedidos WHERE mesa_id = ? AND status != 'pago' ORDER BY data_hora_abertura DESC LIMIT 1");
            $pedidoStmt->execute([$mesa_id]);
            $pedido = $pedidoStmt->fetch();

            if ($pedido) {
                $itensStmt = $pdo->prepare("
                    SELECT pi.item_id, c.nome, pi.quantidade, pi.preco_unitario
                    FROM itens_pedido pi
                    JOIN cardapio c ON pi.item_id = c.id
                    WHERE pi.pedido_id = ?
                ");
                $itensStmt->execute([$pedido['id']]);
                $pedido['itens'] = $itensStmt->fetchAll();
                responder(['success' => true, 'pedido' => $pedido]);
            } else {
                responder(['success' => true, 'pedido' => null, 'message' => 'Nenhum pedido aberto para esta mesa.']);
            }
            break;

        case 'salvar_pedido': // Usado para adicionar/atualizar itens e criar novo pedido
            $validacao = validarCampos(['mesa_id', 'itens'], $input);
            if ($validacao) {
                responder(['success' => false, 'error' => $validacao], 400);
            }

            $mesa_id = filter_var($input['mesa_id'], FILTER_VALIDATE_INT);
            $itens = $input['itens']; // Array de itens: [{item_id, quantidade}]
            $pedido_id = isset($input['pedido_id']) ? filter_var($input['pedido_id'], FILTER_VALIDATE_INT) : null;
            $cliente_nome = isset($input['cliente_nome']) ? trim($input['cliente_nome']) : null;


            $pdo->beginTransaction();

            // Verifica se a mesa existe
            $mesaStmt = $pdo->prepare("SELECT id FROM mesas WHERE id = ?");
            $mesaStmt->execute([$mesa_id]);
            if (!$mesaStmt->fetch()) {
                $pdo->rollBack();
                responder(['success' => false, 'error' => 'Mesa não encontrada.'], 404);
            }

            $valor_total_calculado = 0;
            foreach ($itens as $item) {
                 $itemCardapioStmt = $pdo->prepare("SELECT preco FROM cardapio WHERE id = ?");
                 $itemCardapioStmt->execute([$item['item_id']]);
                 $itemCardapio = $itemCardapioStmt->fetch();
                 if (!$itemCardapio) {
                     $pdo->rollBack();
                     responder(['success' => false, 'error' => "Item do cardápio com ID {$item['item_id']} não encontrado."], 400);
                 }
                 $valor_total_calculado += $itemCardapio['preco'] * $item['quantidade'];
            }


            if ($pedido_id) { // Atualizar pedido existente
                $stmt = $pdo->prepare("UPDATE pedidos SET valor_total = ?, cliente_nome = ? WHERE id = ?");
                $stmt->execute([$valor_total_calculado, $cliente_nome, $pedido_id]);
            } else { // Criar novo pedido
                $stmt = $pdo->prepare("INSERT INTO pedidos (mesa_id, valor_total, status, cliente_nome) VALUES (?, ?, 'aberto', ?)");
                $stmt->execute([$mesa_id, $valor_total_calculado, $cliente_nome]);
                $pedido_id = $pdo->lastInsertId();

                // Atualizar status da mesa para 'ocupada' se não for um pedido já existente que está sendo modificado
                $updateMesaStmt = $pdo->prepare("UPDATE mesas SET status = 'ocupada' WHERE id = ? AND status != 'ocupada'");
                $updateMesaStmt->execute([$mesa_id]);
            }

            // Deletar itens antigos e inserir os novos (abordagem simples)
            $deleteItensStmt = $pdo->prepare("DELETE FROM itens_pedido WHERE pedido_id = ?");
            $deleteItensStmt->execute([$pedido_id]);

            $insertItemStmt = $pdo->prepare("INSERT INTO itens_pedido (pedido_id, item_id, quantidade, preco_unitario) VALUES (?, ?, ?, (SELECT preco FROM cardapio WHERE id = ?))");
            foreach ($itens as $item) {
                if ($item['quantidade'] > 0) { // Adiciona apenas se a quantidade for positiva
                    $insertItemStmt->execute([$pedido_id, $item['item_id'], $item['quantidade'], $item['item_id']]);
                }
            }

            $pdo->commit();

            // Recarregar pedido atualizado para retornar ao cliente
            $pedidoStmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
            $pedidoStmt->execute([$pedido_id]);
            $pedidoAtualizado = $pedidoStmt->fetch();

            $itensStmt = $pdo->prepare("
                SELECT pi.item_id, c.nome, pi.quantidade, pi.preco_unitario
                FROM itens_pedido pi
                JOIN cardapio c ON pi.item_id = c.id
                WHERE pi.pedido_id = ?
            ");
            $itensStmt->execute([$pedido_id]);
            $pedidoAtualizado['itens'] = $itensStmt->fetchAll();

            responder(['success' => true, 'pedido' => $pedidoAtualizado, 'message' => 'Pedido salvo com sucesso!']);
            break;

        case 'atualizar_status_pedido':
            $validacao = validarCampos(['pedido_id', 'status'], $input);
            if ($validacao) {
                responder(['success' => false, 'error' => $validacao], 400);
            }
            $pedido_id = filter_var($input['pedido_id'], FILTER_VALIDATE_INT);
            $status = trim($input['status']);
            $permitidos = ['aberto', 'fechado', 'pago']; // Status permitidos

            if (!in_array($status, $permitidos)) {
                responder(['success' => false, 'error' => 'Status inválido.'], 400);
            }

            $stmt = $pdo->prepare("UPDATE pedidos SET status = ? WHERE id = ?");
            $stmt->execute([$status, $pedido_id]);

            if ($stmt->rowCount() > 0) {
                // Se o pedido foi pago, liberar a mesa
                if ($status == 'pago') {
                    $pedidoMesaStmt = $pdo->prepare("SELECT mesa_id FROM pedidos WHERE id = ?");
                    $pedidoMesaStmt->execute([$pedido_id]);
                    $mesa_id_do_pedido = $pedidoMesaStmt->fetchColumn();
                    if ($mesa_id_do_pedido) {
                        $updateMesaStmt = $pdo->prepare("UPDATE mesas SET status = 'disponivel' WHERE id = ?");
                        $updateMesaStmt->execute([$mesa_id_do_pedido]);
                         responder(['success' => true, 'message' => 'Status do pedido atualizado e mesa liberada.', 'mesa_liberada' => true, 'mesa_id' => $mesa_id_do_pedido]);
                    }
                }
                responder(['success' => true, 'message' => 'Status do pedido atualizado com sucesso.']);
            } else {
                responder(['success' => false, 'error' => 'Pedido não encontrado ou status não modificado.'], 404);
            }
            break;


        case 'fechar_conta': // Processar pagamento e mudar status
            $validacao = validarCampos(['pedido_id', 'metodo_pagamento', 'valor_pago'], $input);
            if ($validacao) {
                responder(['success' => false, 'error' => $validacao], 400);
            }

            $pedido_id = filter_var($input['pedido_id'], FILTER_VALIDATE_INT);
            $metodo_pagamento = trim($input['metodo_pagamento']);
            $valor_pago = filter_var($input['valor_pago'], FILTER_VALIDATE_FLOAT);
            $cliente_nome_pagamento = isset($input['cliente_nome']) ? trim($input['cliente_nome']) : null;


            $pdo->beginTransaction();

            $pedidoStmt = $pdo->prepare("SELECT mesa_id, valor_total, status FROM pedidos WHERE id = ?");
            $pedidoStmt->execute([$pedido_id]);
            $pedido = $pedidoStmt->fetch();

            if (!$pedido) {
                $pdo->rollBack();
                responder(['success' => false, 'error' => 'Pedido não encontrado.'], 404);
            }
            if ($pedido['status'] == 'pago') {
                $pdo->rollBack();
                responder(['success' => false, 'error' => 'Este pedido já foi pago.'], 400);
            }
             if ($valor_pago < $pedido['valor_total']) {
                $pdo->rollBack();
                responder(['success' => false, 'error' => 'Valor pago é menor que o total do pedido.'], 400);
            }


            $updatePedidoStmt = $pdo->prepare("UPDATE pedidos SET status = 'pago', metodo_pagamento = ?, valor_pago = ?, data_hora_fechamento = NOW(), cliente_nome = COALESCE(?, cliente_nome) WHERE id = ?");
            $updatePedidoStmt->execute([$metodo_pagamento, $valor_pago, $cliente_nome_pagamento, $pedido_id]);

            $updateMesaStmt = $pdo->prepare("UPDATE mesas SET status = 'disponivel' WHERE id = ?");
            $updateMesaStmt->execute([$pedido['mesa_id']]);

            $pdo->commit();
            responder(['success' => true, 'message' => 'Conta fechada e pagamento registrado com sucesso.', 'mesa_id_liberada' => $pedido['mesa_id']]);
            break;

        case 'adicionar_mesa':
            $validacao = validarCampos(['numero', 'capacidade'], $input);
            if ($validacao) {
                responder(['success' => false, 'error' => $validacao], 400);
            }
            $numero = filter_var($input['numero'], FILTER_VALIDATE_INT);
            $capacidade = filter_var($input['capacidade'], FILTER_VALIDATE_INT);

            if ($numero === false || $numero <=0 || $capacidade === false || $capacidade <=0) {
                 responder(['success' => false, 'error' => 'Número da mesa e capacidade devem ser números positivos.'], 400);
            }

            //Verificar se numero da mesa já existe
            $checkStmt = $pdo->prepare("SELECT id FROM mesas WHERE numero = ?");
            $checkStmt->execute([$numero]);
            if($checkStmt->fetch()){
                responder(['success' => false, 'error' => "Mesa número {$numero} já existe."], 409); // 409 Conflict
            }


            $stmt = $pdo->prepare("INSERT INTO mesas (numero, capacidade, status) VALUES (?, ?, 'disponivel')");
            if ($stmt->execute([$numero, $capacidade])) {
                $id_nova_mesa = $pdo->lastInsertId();
                $novaMesaStmt = $pdo->prepare("SELECT * FROM mesas WHERE id = ?");
                $novaMesaStmt->execute([$id_nova_mesa]);
                $novaMesa = $novaMesaStmt->fetch();
                responder(['success' => true, 'mesa' => $novaMesa, 'message' => 'Mesa adicionada com sucesso!']);
            } else {
                responder(['success' => false, 'error' => 'Erro ao adicionar mesa.'], 500);
            }
            break;

        case 'adicionar_item_cardapio':
            $validacao = validarCampos(['nome', 'categoria', 'preco'], $input);
            if ($validacao) {
                responder(['success' => false, 'error' => $validacao], 400);
            }
            $nome = trim($input['nome']);
            $categoria = trim($input['categoria']);
            $preco = filter_var($input['preco'], FILTER_VALIDATE_FLOAT);
            $descricao = isset($input['descricao']) ? trim($input['descricao']) : null;

            if ($preco === false || $preco < 0) {
                 responder(['success' => false, 'error' => 'Preço deve ser um número não negativo.'], 400);
            }

            $stmt = $pdo->prepare("INSERT INTO cardapio (nome, categoria, preco, descricao) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$nome, $categoria, $preco, $descricao])) {
                $id_novo_item = $pdo->lastInsertId();
                $novoItemStmt = $pdo->prepare("SELECT * FROM cardapio WHERE id = ?");
                $novoItemStmt->execute([$id_novo_item]);
                $novoItem = $novoItemStmt->fetch();
                responder(['success' => true, 'item' => $novoItem, 'message' => 'Item adicionado ao cardápio com sucesso!']);
            } else {
                responder(['success' => false, 'error' => 'Erro ao adicionar item ao cardápio.'], 500);
            }
            break;

        case 'gerar_relatorio_diario':
            $data = $_GET['data'] ?? date('Y-m-d'); // Usa data atual se não especificada
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                responder(['success' => false, 'error' => 'Formato de data inválido. Use YYYY-MM-DD.'], 400);
            }


            $totalVendasStmt = $pdo->prepare("SELECT SUM(valor_total) as total_vendas, COUNT(id) as total_pedidos FROM pedidos WHERE status = 'pago' AND DATE(data_hora_fechamento) = ?");
            $totalVendasStmt->execute([$data]);
            $relatorio = $totalVendasStmt->fetch();

            $itensVendidosStmt = $pdo->prepare("
                SELECT c.nome, SUM(ip.quantidade) as quantidade_vendida, SUM(ip.quantidade * ip.preco_unitario) as valor_total_item
                FROM itens_pedido ip
                JOIN cardapio c ON ip.item_id = c.id
                JOIN pedidos p ON ip.pedido_id = p.id
                WHERE p.status = 'pago' AND DATE(p.data_hora_fechamento) = ?
                GROUP BY c.nome
                ORDER BY quantidade_vendida DESC
            ");
            $itensVendidosStmt->execute([$data]);
            $relatorio['itens_vendidos'] = $itensVendidosStmt->fetchAll();

            responder(['success' => true, 'relatorio' => $relatorio]);
            break;

        case 'atualizar_status_mesa': // Ação específica para liberar mesa sem fechar conta (ex: mesa vazia)
            $validacao = validarCampos(['mesa_id', 'status'], $input);
             if ($validacao) {
                responder(['success' => false, 'error' => $validacao], 400);
            }
            $mesa_id = filter_var($input['mesa_id'], FILTER_VALIDATE_INT);
            $status = trim($input['status']);

            if ($status !== 'disponivel') { // Por enquanto, só permite liberar
                 responder(['success' => false, 'error' => "Esta ação só permite definir a mesa como 'disponivel'."], 400);
            }

            // Verifica se há pedidos abertos ou fechados (não pagos) para esta mesa
            $checkPedidosStmt = $pdo->prepare("SELECT COUNT(id) as count_pedidos FROM pedidos WHERE mesa_id = ? AND (status = 'aberto' OR status = 'fechado')");
            $checkPedidosStmt->execute([$mesa_id]);
            $pedidosPendentes = $checkPedidosStmt->fetchColumn();

            if ($pedidosPendentes > 0) {
                responder(['success' => false, 'error' => "Não é possível liberar a mesa. Existem {$pedidosPendentes} pedidos pendentes (abertos/fechados)."], 400);
            }

            $stmt = $pdo->prepare("UPDATE mesas SET status = ? WHERE id = ?");
            $stmt->execute([$status, $mesa_id]);

            if ($stmt->rowCount() > 0) {
                responder(['success' => true, 'message' => "Status da mesa {$mesa_id} atualizado para {$status}."]);
            } else {
                responder(['success' => false, 'error' => 'Mesa não encontrada ou status não modificado.'], 404);
            }
            break;

        default:
            responder(['success' => false, 'error' => 'Ação não reconhecida.'], 404);
            break;
    }
} catch (PDOException $e) {
    error_log("PDOException: " . $e->getMessage());
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder(['success' => false, 'error' => 'Erro no banco de dados: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    responder(['success' => false, 'error' => 'Erro inesperado no servidor: ' . $e->getMessage()], 500);
}
?>
