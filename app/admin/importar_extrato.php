<?php
/**
 * =====================================================================
 * IMPORTAÇÃO DE EXTRATO BANCÁRIO - COM IA APRENDIZADO E MÚLTIPLOS ORÇAMENTOS
 * =====================================================================
 */

session_start();
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensagem = '';
$erro = '';
$transacoes_importadas = [];

// Função para parse de arquivo OFX
function parseOFX($conteudo) {
    $transacoes = [];
    preg_match_all('/<STMTTRN>.*?<\/STMTTRN>/s', $conteudo, $matches);
    
    foreach ($matches[0] as $transacao) {
        preg_match('/<DTPOSTED>(\d{4})(\d{2})(\d{2})/', $transacao, $data_match);
        $data = $data_match ? "{$data_match[1]}-{$data_match[2]}-{$data_match[3]}" : date('Y-m-d');
        preg_match('/<TRNAMT>(-?\d+\.\d+)/', $transacao, $valor_match);
        $valor = $valor_match ? floatval($valor_match[1]) : 0;
        preg_match('/<MEMO>(.*?)<\/MEMO>/', $transacao, $memo_match);
        $descricao = $memo_match ? trim($memo_match[1]) : '';
        if (empty($descricao)) {
            preg_match('/<NAME>(.*?)<\/NAME>/', $transacao, $name_match);
            $descricao = $name_match ? trim($name_match[1]) : '';
        }
        $descricao = trim(preg_replace('/\s+/', ' ', $descricao));
        
        if ($valor != 0 && !empty($descricao)) {
            $transacoes[] = [
                'data' => $data, 'valor' => abs($valor),
                'tipo' => $valor < 0 ? 'saida' : 'entrada',
                'descricao' => $descricao, 'original_descricao' => $descricao
            ];
        }
    }
    return $transacoes;
}

// Função para parse de CSV
function parseCSV($conteudo) {
    $transacoes = [];
    $linhas = explode("\n", $conteudo);
    array_shift($linhas);
    
    foreach ($linhas as $linha) {
        if (empty(trim($linha))) continue;
        $dados = str_getcsv($linha);
        if (count($dados) < 2) continue;
        
        $data = isset($dados[0]) ? date('Y-m-d', strtotime($dados[0])) : date('Y-m-d');
        $valor_str = str_replace(['R$', ' ', '.', ','], ['', '', '', '.'], $dados[1] ?? '0');
        $valor = floatval($valor_str);
        $descricao = $dados[3] ?? $dados[2] ?? 'Transação';
        
        if ($valor != 0) {
            $transacoes[] = [
                'data' => $data, 'valor' => abs($valor),
                'tipo' => $valor < 0 ? 'saida' : 'entrada',
                'descricao' => $descricao, 'original_descricao' => $descricao
            ];
        }
    }
    return $transacoes;
}

// Função para verificar se transação já existe (evitar duplicidade)
function transacaoExiste($conexao, $data, $valor, $descricao) {
    $desc_like = '%' . addslashes(substr($descricao, 0, 50)) . '%';
    $sql = "SELECT id FROM financeiro 
            WHERE data_transacao = '$data' 
            AND ABS(valor - $valor) < 0.01
            AND (descricao LIKE '$desc_like' OR observacao LIKE '$desc_like')
            LIMIT 1";
    $result = $conexao->query($sql);
    return $result && $result->num_rows > 0;
}

// Função para aprender padrão (salvar no histórico)
function aprenderPadrao($conexao, $descricao, $tipo, $categoria, $cliente_id = null, $orcamento_id = null) {
    // Extrair palavra-chave da descrição
    $palavras = explode(' ', strtoupper($descricao));
    $palavra_chave = '';
    foreach ($palavras as $palavra) {
        if (strlen($palavra) > 3 && !preg_match('/\d/', $palavra)) {
            $palavra_chave = $palavra;
            break;
        }
    }
    if (empty($palavra_chave)) return;
    
    // Verificar se já existe
    $check = $conexao->query("SELECT id, vezes_usado FROM padroes_importacao WHERE palavra_chave = '$palavra_chave' AND tipo = '$tipo'");
    if ($check && $check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $novas_vezes = $row['vezes_usado'] + 1;
        $conexao->query("UPDATE padroes_importacao SET vezes_usado = $novas_vezes, ultimo_uso = CURDATE() WHERE id = {$row['id']}");
    } else {
        $cliente = $cliente_id ? $cliente_id : 'NULL';
        $orcamento = $orcamento_id ? $orcamento_id : 'NULL';
        $conexao->query("INSERT INTO padroes_importacao (palavra_chave, tipo, categoria, cliente_id, orcamento_id, vezes_usado, ultimo_uso) 
                         VALUES ('$palavra_chave', '$tipo', '$categoria', $cliente, $orcamento, 1, CURDATE())");
    }
}

// Função para sugerir baseado em aprendizado
function sugerirPorAprendizado($conexao, $descricao, $tipo) {
    $palavras = explode(' ', strtoupper($descricao));
    foreach ($palavras as $palavra) {
        if (strlen($palavra) > 3) {
            $sql = "SELECT categoria, cliente_id, orcamento_id, vezes_usado 
                    FROM padroes_importacao 
                    WHERE palavra_chave = '$palavra' AND tipo = '$tipo'
                    ORDER BY vezes_usado DESC LIMIT 1";
            $result = $conexao->query($sql);
            if ($result && $result->num_rows > 0) {
                return $result->fetch_assoc();
            }
        }
    }
    return null;
}

// Função de IA para classificar transação (com aprendizado)
function iaClassificarTransacao($conexao, $descricao, $valor, $tipo) {
    $desc_lower = strtolower($descricao);
    
    // Verificar aprendizado anterior
    $aprendizado = sugerirPorAprendizado($conexao, $descricao, $tipo);
    if ($aprendizado) {
        return [
            'categoria' => $aprendizado['categoria'],
            'confianca' => 90,
            'cliente_sugerido' => $aprendizado['cliente_id'],
            'orcamento_sugerido' => $aprendizado['orcamento_id']
        ];
    }
    
    $categoria = '';
    $confianca = 0;
    
    $palavras_saida = [
        'materiais' => ['material', 'insumo', 'peca', 'ferramenta', 'equipamento', 'produto', 'hardware', 'ferragem', 'tinta', 'madeira'],
        'fornecedores' => ['fornecedor', 'compra', 'aquisição', 'forn', 'distribuidor'],
        'funcionarios' => ['salario', 'funcionario', 'colaborador', 'holerite', 'fgts', 'vale'],
        'impostos' => ['imposto', 'taxa', 'iptu', 'iss', 'icms', 'simples', 'das', 'certidao'],
        'aluguel' => ['aluguel', 'aluguer', 'locação', 'alug'],
        'energia' => ['energia', 'luz', 'eletricidade', 'cpfl', 'energisa', 'eletropaulo'],
        'agua' => ['agua', 'sabesp', 'sanepar', 'tratamento'],
        'internet' => ['internet', 'telefone', 'vivo', 'claro', 'tim', 'oi', 'fibra', 'net'],
        'marketing' => ['marketing', 'publicidade', 'ads', 'google', 'facebook', 'instagram', 'anuncio', 'meta'],
        'alimentacao' => ['mercado', 'supermercado', 'feira', 'restaurante', 'lanche', 'pizza', 'hamburguer', 'ifood', 'rappi', 'comida', 'bebida', 'padaria', 'açougue', 'hortifruti', 'sacolão'],
        'combustivel' => ['posto', 'gasolina', 'etanol', 'diesel', 'combustivel', 'combustível', 'shell', 'ipiranga', 'br', 'alcool', 'gas', 'petrobras'],
        'transporte' => ['uber', '99', 'taxi', 'transporte', 'passagem', 'onibus', 'metro', 'trem', 'pedagio', 'estacionamento'],
        'saude' => ['farmacia', 'drogaria', 'medico', 'consulta', 'exame', 'dentista', 'hospital', 'plano saude', 'unimed', 'amil'],
        'educacao' => ['escola', 'faculdade', 'curso', 'livro', 'material escolar', 'universidade', 'ensino', 'graduação'],
        'lazer' => ['cinema', 'teatro', 'show', 'parque', 'academia', 'esporte', 'viagem', 'hotel', 'pousada'],
        'vestuario' => ['roupa', 'calçado', 'sapato', 'camisa', 'calca', 'vestido', 'loja', 'moda'],
        'casa' => ['construção', 'reforma', 'moveis', 'eletrodomestico', 'utilidades', 'decoração', 'ferragem'],
        'pet' => ['pet', 'cachorro', 'gato', 'veterinario', 'ração', 'pet shop', 'animal'],
        'pessoal' => ['cabelo', 'barbearia', 'salão', 'estetica', 'manicure', 'academia']
    ];
    
    $palavras_entrada = [
        'vendas' => ['venda', 'servico', 'instalação', 'manutencao', 'limpeza', 'projeto', 'reparo'],
        'cobrancas' => ['cobranca', 'boleto', 'parcela', 'recebimento', 'credito'],
        'cliente' => ['cliente', 'pagamento', 'recebido', 'transferencia', 'deposito', 'pix recebido']
    ];
    
    if ($tipo == 'saida') {
        foreach ($palavras_saida as $cat => $palavras) {
            foreach ($palavras as $palavra) {
                if (strpos($desc_lower, $palavra) !== false) {
                    $categoria = $cat;
                    $confianca = 80;
                    break 2;
                }
            }
        }
        if (empty($categoria)) {
            if (preg_match('/(MERCADO|SUPERMERCADO|ATACADAO|ATACADO)/i', $desc_lower)) {
                $categoria = 'alimentacao'; $confianca = 70;
            } elseif (preg_match('/(POSTO|GASOLINA|COMBUSTIVEL)/i', $desc_lower)) {
                $categoria = 'combustivel'; $confianca = 80;
            } elseif (preg_match('/(UBER|99|TAXI)/i', $desc_lower)) {
                $categoria = 'transporte'; $confianca = 75;
            } elseif (preg_match('/(FARMACIA|DROGARIA|MEDICO)/i', $desc_lower)) {
                $categoria = 'saude'; $confianca = 70;
            } elseif (preg_match('/(HARDWARE|ELETRICA|TUBOS|COBRE|AR CONDICIONADO)/i', $desc_lower)) {
                $categoria = 'materiais'; $confianca = 70;
            } else {
                $categoria = 'outras_saidas'; $confianca = 30;
            }
        }
    } else {
        foreach ($palavras_entrada as $cat => $palavras) {
            foreach ($palavras as $palavra) {
                if (strpos($desc_lower, $palavra) !== false) {
                    $categoria = $cat;
                    $confianca = 80;
                    break 2;
                }
            }
        }
        if (empty($categoria)) {
            $categoria = 'outras_entradas'; $confianca = 30;
        }
    }
    
    return [
        'categoria' => $categoria,
        'confianca' => $confianca,
        'cliente_sugerido' => null,
        'orcamento_sugerido' => null
    ];
}

// Processar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['arquivo_extrato']) && $_FILES['arquivo_extrato']['error'] === UPLOAD_ERR_OK) {
        $arquivo = $_FILES['arquivo_extrato'];
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $conteudo = file_get_contents($arquivo['tmp_name']);
        
        if ($extensao === 'ofx' || $extensao === 'ofc') {
            $transacoes_importadas = parseOFX($conteudo);
        } elseif ($extensao === 'csv') {
            $transacoes_importadas = parseCSV($conteudo);
        } else {
            $erro = "Formato não suportado. Use OFX ou CSV.";
        }
        
        if (!empty($transacoes_importadas)) {
            // Filtrar transações que já existem (evitar duplicidade)
            $transacoes_novas = [];
            foreach ($transacoes_importadas as $trans) {
                if (!transacaoExiste($conexao, $trans['data'], $trans['valor'], $trans['descricao'])) {
                    $classificacao = iaClassificarTransacao($conexao, $trans['descricao'], $trans['valor'], $trans['tipo']);
                    $trans['categoria_sugerida'] = $classificacao['categoria'];
                    $trans['confianca'] = $classificacao['confianca'];
                    $trans['cliente_sugerido'] = $classificacao['cliente_sugerido'];
                    $trans['orcamento_sugerido'] = $classificacao['orcamento_sugerido'];
                    $transacoes_novas[] = $trans;
                }
            }
            
            if (empty($transacoes_novas)) {
                $erro = "Todas as transações já foram importadas anteriormente. Nenhuma nova para processar.";
            } else {
                $_SESSION['transacoes_pendentes'] = $transacoes_novas;
                header('Location: ' . BASE_URL . '/app/admin/importar_extrato.php?acao=classificar');
                exit;
            }
        } else {
            $erro = "Nenhuma transação encontrada no arquivo.";
        }
    }
    
    // Salvar classificações
    if (isset($_POST['acao']) && $_POST['acao'] === 'salvar_classificacoes') {
        $classificacoes = $_POST['classificacao'] ?? [];
        $clientes_ids = $_POST['cliente_id'] ?? [];
        $orcamentos_ids = $_POST['orcamento_id'] ?? [];
        $descricoes_personalizadas = $_POST['descricao_personalizada'] ?? [];
        $orcamentos_multiplos = $_POST['orcamento_multiplo'] ?? [];
        $valores_parciais = $_POST['valor_parcial'] ?? [];
        $importadas = 0;
        
        foreach ($classificacoes as $index => $categoria) {
            if (empty($categoria)) continue;
            $transacao = $_SESSION['transacoes_pendentes'][$index] ?? null;
            if (!$transacao) continue;
            
            $cliente_id = !empty($clientes_ids[$index]) ? intval($clientes_ids[$index]) : 'NULL';
            $descricao_final = !empty($descricoes_personalizadas[$index]) ? addslashes($descricoes_personalizadas[$index]) : addslashes($transacao['descricao']);
            $observacao = "Importado do extrato - Original: " . addslashes($transacao['original_descricao']);
            $valor_total = $transacao['valor'];
            
            // Iniciar transação
            $conexao->begin_transaction();
            
            try {
                // Registrar no financeiro
                $sql = "INSERT INTO financeiro (tipo, valor, descricao, categoria, cliente_id, data_transacao, forma_pagamento, observacao) 
                        VALUES ('{$transacao['tipo']}', {$valor_total}, '{$descricao_final}', '{$categoria}', {$cliente_id}, '{$transacao['data']}', 'transferencia', '{$observacao}')";
                
                if (!$conexao->query($sql)) {
                    throw new Exception("Erro ao inserir financeiro: " . $conexao->error);
                }
                $financeiro_id = $conexao->insert_id;
                
                // Processar orçamentos (pode ser múltiplo)
                if (!empty($orcamentos_multiplos[$index]) && is_array($orcamentos_multiplos[$index])) {
                    $valor_restante = $valor_total;
                    
                    foreach ($orcamentos_multiplos[$index] as $orc_index => $orcamento_id) {
                        if ($valor_restante <= 0) break;
                        
                        $valor_parcial = isset($valores_parciais[$index][$orc_index]) && !empty($valores_parciais[$index][$orc_index])
                            ? floatval(str_replace(',', '.', str_replace('.', '', $valores_parciais[$index][$orc_index])))
                            : $valor_restante;
                        
                        if ($valor_parcial > $valor_restante) $valor_parcial = $valor_restante;
                        if ($valor_parcial <= 0) continue;
                        
                        // Registrar pagamento do orçamento
                        $sql_pag = "INSERT INTO pagamentos_orcamentos (orcamento_id, financeiro_id, valor_pago, data_pagamento) 
                                    VALUES ($orcamento_id, $financeiro_id, $valor_parcial, '{$transacao['data']}')";
                        $conexao->query($sql_pag);
                        
                        // Registrar cobrança (se necessário)
                        $numero_cob = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                        $sql_cob = "INSERT INTO cobrancas (numero, cliente_id, orcamento_id, valor, data_vencimento, status, data_recebimento, observacao) 
                                    VALUES ('$numero_cob', $cliente_id, $orcamento_id, $valor_parcial, CURDATE(), 'recebida', CURDATE(), 'Pagamento parcial via importação de extrato')";
                        $conexao->query($sql_cob);
                        
                        $valor_restante -= $valor_parcial;
                    }
                } elseif (!empty($orcamentos_ids[$index])) {
                    // Pagamento único
                    $sql_pag = "INSERT INTO pagamentos_orcamentos (orcamento_id, financeiro_id, valor_pago, data_pagamento) 
                                VALUES ({$orcamentos_ids[$index]}, $financeiro_id, {$valor_total}, '{$transacao['data']}')";
                    $conexao->query($sql_pag);
                    
                    $numero_cob = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                    $sql_cob = "INSERT INTO cobrancas (numero, cliente_id, orcamento_id, valor, data_vencimento, status, data_recebimento, observacao) 
                                VALUES ('$numero_cob', $cliente_id, {$orcamentos_ids[$index]}, {$valor_total}, CURDATE(), 'recebida', CURDATE(), 'Pagamento via importação de extrato')";
                    $conexao->query($sql_cob);
                }
                
                // Aprender padrão para próximas importações
                if ($cliente_id != 'NULL') {
                    aprenderPadrao($conexao, $transacao['descricao'], $transacao['tipo'], $categoria, $cliente_id, $orcamentos_ids[$index] ?? null);
                }
                
                $conexao->commit();
                $importadas++;
                
            } catch (Exception $e) {
                $conexao->rollback();
                $erro = "Erro ao processar transação: " . $e->getMessage();
                break;
            }
        }
        
        unset($_SESSION['transacoes_pendentes']);
        $_SESSION['mensagem'] = "$importadas transações importadas com sucesso!";
        header('Location: ' . BASE_URL . '/app/admin/financeiro.php');
        exit;
    }
}

// Carregar dados
$clientes = [];
$result = $conexao->query("SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC");
if ($result) while ($row = $result->fetch_assoc()) $clientes[] = $row;

$categorias_entrada = [
    'vendas' => '💰 Venda de Produto/Serviço',
    'cobrancas' => '📄 Recebimento de Cobrança',
    'cliente' => '👤 Pagamento de Cliente',
    'outras_entradas' => '📌 Outras Entradas'
];

$categorias_saida = [
    'materiais' => '🔧 Materiais/Insumos',
    'fornecedores' => '🏭 Pagamento Fornecedor',
    'funcionarios' => '👨‍💼 Funcionários/Salários',
    'impostos' => '📊 Impostos/Taxas',
    'aluguel' => '🏠 Aluguel',
    'energia' => '⚡ Energia Elétrica',
    'agua' => '💧 Água',
    'internet' => '🌐 Internet/Telefone',
    'marketing' => '📢 Marketing/Publicidade',
    'alimentacao' => '🍔 Alimentação/Mercado',
    'combustivel' => '⛽ Combustível',
    'transporte' => '🚗 Transporte/Uber',
    'saude' => '🏥 Saúde/Farmácia',
    'educacao' => '📚 Educação/Cursos',
    'lazer' => '🎬 Lazer/Entretenimento',
    'vestuario' => '👕 Vestuário/Calçados',
    'casa' => '🏠 Casa/Construção',
    'pet' => '🐾 Pet/Cuidados',
    'pessoal' => '💇 Cuidados Pessoais',
    'outras_saidas' => '📌 Outras Saídas'
];

$transacoes_pendentes = $_SESSION['transacoes_pendentes'] ?? [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Extrato - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #1e3c72; --secondary: #2a5298; --success: #28a745; --danger: #dc3545; --warning: #ffc107; --info: #17a2b8; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 300px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 20px; position: fixed; height: 100vh; z-index: 1000; overflow-y: auto; }
        .main-content { flex: 1; margin-left: 300px; padding: 30px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 20px; background: white; border-radius: 12px; }
        .page-header h1 { color: var(--primary); font-size: 28px; display: flex; align-items: center; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; transition: all 0.3s; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34ce57); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-info { background: linear-gradient(135deg, var(--info), #138496); color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .card-header { padding: 20px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .card-header h3 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; }
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: white; }
        .table thead { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .table th, .table td { padding: 15px; border-bottom: 1px solid #e0e0e0; text-align: left; vertical-align: top; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-high { background: #d4edda; color: #155724; }
        .badge-medium { background: #fff3cd; color: #856404; }
        .badge-low { background: #f8d7da; color: #721c24; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .upload-area { border: 2px dashed #dee2e6; border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { border-color: var(--primary); background: #f8f9fa; }
        .valor-positivo { color: var(--success); font-weight: bold; }
        .valor-negativo { color: var(--danger); font-weight: bold; }
        .ia-sugestao { background: #e8f4fd; border-left: 3px solid var(--info); padding: 5px 10px; border-radius: 4px; font-size: 12px; margin-top: 5px; }
        .loading { display: inline-block; width: 16px; height: 16px; border: 2px solid #f3f3f3; border-top: 2px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; margin-left: 10px; }
        .orcamento-item { background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #e0e0e0; }
        .btn-add-orcamento { background: var(--info); color: white; border: none; border-radius: 4px; padding: 5px 10px; font-size: 12px; cursor: pointer; margin-top: 5px; }
        .btn-remove-orcamento { background: var(--danger); color: white; border: none; border-radius: 4px; padding: 5px 10px; font-size: 12px; cursor: pointer; margin-left: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @media (max-width: 768px) { .sidebar { width: 100%; height: auto; position: relative; } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">
            
            <?php if (isset($_SESSION['mensagem'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['mensagem']; unset($_SESSION['mensagem']); ?></div>
            <?php endif; ?>
            <?php if ($erro): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['acao']) && $_GET['acao'] === 'classificar' && !empty($transacoes_pendentes)): ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-robot"></i> Classificar Transações</h1>
                    <div><a href="importar_extrato.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a></div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-microchip"></i> <strong>Classificação Inteligente (IA)</strong> - A IA analisou cada transação.<br>
                    <strong>Confiança:</strong> <span class="badge badge-high">Alta (80%+)</span> <span class="badge badge-medium">Média (50-79%)</span> <span class="badge badge-low">Baixa (<50%)</span>
                </div>

                <form method="POST" id="form-classificar">
                    <input type="hidden" name="acao" value="salvar_classificacoes">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Data</th><th>Descrição Original</th><th>Descrição Final</th><th>Valor</th><th>Tipo</th><th>Categoria</th><th>Vincular Cliente</th><th>Vincular Orçamento(s)</th></tr></thead><tbody>
                        <?php foreach ($transacoes_pendentes as $index => $trans): 
                            $confianca = $trans['confianca'] ?? 0;
                            $badge_classe = $confianca >= 80 ? 'badge-high' : ($confianca >= 50 ? 'badge-medium' : 'badge-low');
                        ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($trans['data'])); ?></td>
                            <td><?php echo htmlspecialchars($trans['descricao']); ?>
                                <div class="ia-sugestao"><i class="fas fa-robot"></i> IA: <strong><?php if ($trans['tipo'] == 'entrada') echo $categorias_entrada[$trans['categoria_sugerida']] ?? $trans['categoria_sugerida']; else echo $categorias_saida[$trans['categoria_sugerida']] ?? $trans['categoria_sugerida']; ?></strong> <span class="badge <?php echo $badge_classe; ?>"><?php echo $confianca; ?>%</span></div>
                            </td>
                            <td><input type="text" name="descricao_personalizada[<?php echo $index; ?>]" class="form-control" value="<?php echo htmlspecialchars($trans['descricao']); ?>"></td>
                            <td class="<?php echo $trans['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>"><?php echo 'R$ ' . number_format($trans['valor'], 2, ',', '.'); ?></td>
                            <td><span class="badge badge-<?php echo $trans['tipo'] == 'entrada' ? 'success' : 'danger'; ?>"><?php echo $trans['tipo'] == 'entrada' ? 'Entrada' : 'Saída'; ?></span></td>
                            <td><select name="classificacao[<?php echo $index; ?>]" class="form-control" required>
                                <option value="">Selecione</option>
                                <?php if ($trans['tipo'] == 'entrada'): ?>
                                    <?php foreach ($categorias_entrada as $key => $cat): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $trans['categoria_sugerida'] == $key ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($categorias_saida as $key => $cat): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $trans['categoria_sugerida'] == $key ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select></td>
                            <td>
                                <select name="cliente_id[<?php echo $index; ?>]" class="form-control cliente-select" data-index="<?php echo $index; ?>" onchange="carregarOrcamentos(this.value, <?php echo $index; ?>)">
                                    <option value="">Nenhum</option>
                                    <?php foreach ($clientes as $cli): ?>
                                        <option value="<?php echo $cli['id']; ?>"><?php echo htmlspecialchars($cli['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <div id="orcamento-container-<?php echo $index; ?>">
                                    <select name="orcamento_id[<?php echo $index; ?>]" class="form-control">
                                        <option value="">Selecione um cliente primeiro</option>
                                    </select>
                                    <button type="button" class="btn-add-orcamento" onclick="adicionarOrcamento(<?php echo $index; ?>)">+ Adicionar outro orçamento</button>
                                </div>
                                <div id="orcamentos-multiplos-<?php echo $index; ?>"></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody></table></div></div>

                    <div style="margin-top:30px; text-align:right;"><button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save"></i> Salvar e Importar</button><a href="importar_extrato.php" class="btn btn-secondary btn-lg">Cancelar</a></div>
                </form>

                <script>
                    let orcamentosDisponiveis = {};
                    
                    function carregarOrcamentos(clienteId, index) {
                        const container = document.getElementById('orcamento-container-' + index);
                        
                        if (!clienteId || clienteId === '') {
                            container.innerHTML = '<select name="orcamento_id[' + index + ']" class="form-control"><option value="">Nenhum orçamento disponível</option></select><button type="button" class="btn-add-orcamento" onclick="adicionarOrcamento(' + index + ')">+ Adicionar outro orçamento</button>';
                            return;
                        }
                        
                        container.innerHTML = '<div class="loading"></div> Carregando orçamentos...';
                        
                        fetch('ajax_orcamentos.php?cliente_id=' + clienteId)
                            .then(response => response.json())
                            .then(data => {
                                orcamentosDisponiveis[clienteId] = data;
                                
                                let html = '<select name="orcamento_id[' + index + ']" class="form-control" onchange="mostrarSaldoPendente(this, ' + index + ')">';
                                html += '<option value="">Selecione um orçamento</option>';
                                
                                if (data.length === 0) {
                                    html = '<select name="orcamento_id[' + index + ']" class="form-control"><option value="">Nenhum orçamento para este cliente</option></select>';
                                } else {
                                    data.forEach(orc => {
                                        const valorFormatado = parseFloat(orc.valor_total).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                        const saldoFormatado = parseFloat(orc.saldo_pendente).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                        html += `<option value="${orc.id}" data-saldo="${orc.saldo_pendente}">${orc.numero} - R$ ${valorFormatado} (Saldo: R$ ${saldoFormatado})</option>`;
                                    });
                                    html += '</select>';
                                }
                                
                                html += '<button type="button" class="btn-add-orcamento" onclick="adicionarOrcamento(' + index + ')">+ Adicionar outro orçamento</button>';
                                container.innerHTML = html;
                            })
                            .catch(error => {
                                console.error('Erro:', error);
                                container.innerHTML = '<select name="orcamento_id[' + index + ']" class="form-control"><option value="">Erro ao carregar</option></select><button type="button" class="btn-add-orcamento" onclick="adicionarOrcamento(' + index + ')">+ Adicionar outro orçamento</button>';
                            });
                    }
                    
                    function adicionarOrcamento(index) {
                        const clienteSelect = document.querySelector(`select[name="cliente_id[${index}]"]`);
                        const clienteId = clienteSelect.value;
                        
                        if (!clienteId || clienteId === '') {
                            alert('Selecione um cliente primeiro');
                            return;
                        }
                        
                        const container = document.getElementById('orcamentos-multiplos-' + index);
                        const orcamentos = orcamentosDisponiveis[clienteId] || [];
                        
                        if (orcamentos.length === 0) {
                            alert('Este cliente não tem orçamentos disponíveis');
                            return;
                        }
                        
                        const novoId = Date.now();
                        let html = `<div class="orcamento-item" id="orc-mult-${novoId}">`;
                        html += `<select name="orcamento_multiplo[${index}][]" class="form-control" style="width: calc(100% - 80px); display: inline-block;" onchange="mostrarSaldoPendenteMultiplo(this, '${novoId}')">`;
                        html += `<option value="">Selecione um orçamento</option>`;
                        orcamentos.forEach(orc => {
                            const valorFormatado = parseFloat(orc.valor_total).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                            const saldoFormatado = parseFloat(orc.saldo_pendente).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                            html += `<option value="${orc.id}" data-saldo="${orc.saldo_pendente}">${orc.numero} - R$ ${valorFormatado} (Saldo: R$ ${saldoFormatado})</option>`;
                        });
                        html += `</select>`;
                        html += `<button type="button" class="btn-remove-orcamento" onclick="removerOrcamento('orc-mult-${novoId}')">✕</button>`;
                        html += `<div style="margin-top: 8px;"><label style="font-size: 12px;">Valor a pagar neste orçamento:</label>`;
                        html += `<input type="text" name="valor_parcial[${index}][${novoId}]" class="form-control money" placeholder="Valor total" style="width: 200px; display: inline-block; margin-left: 10px;">`;
                        html += `</div></div>`;
                        
                        container.insertAdjacentHTML('beforeend', html);
                        
                        // Aplicar máscara de moeda
                        const moneyInput = container.querySelector(`#orc-mult-${novoId} input.money`);
                        if (moneyInput) {
                            moneyInput.addEventListener('input', function(e) {
                                let value = e.target.value.replace(/\D/g, '');
                                if (value === '') { e.target.value = '0,00'; return; }
                                if (value.length > 2) { const reais = value.slice(0, -2); const centavos = value.slice(-2); value = reais + ',' + centavos; }
                                else if (value.length === 2) value = '0,' + value;
                                else if (value.length === 1) value = '0,0' + value;
                                value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                e.target.value = 'R$ ' + value;
                            });
                        }
                    }
                    
                    function removerOrcamento(id) {
                        document.getElementById(id).remove();
                    }
                    
                    function mostrarSaldoPendente(select, index) {
                        const option = select.options[select.selectedIndex];
                        const saldo = option.getAttribute('data-saldo');
                        if (saldo && parseFloat(saldo) > 0) {
                            // Pode mostrar alerta ou tooltip
                        }
                    }
                    
                    function mostrarSaldoPendenteMultiplo(select, id) {
                        const option = select.options[select.selectedIndex];
                        const saldo = option.getAttribute('data-saldo');
                        const container = document.getElementById(id);
                        const input = container.querySelector('input.money');
                        if (saldo && parseFloat(saldo) > 0 && input && !input.value) {
                            input.value = 'R$ ' + parseFloat(saldo).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        }
                    }
                    
                    document.querySelectorAll('.money').forEach(input => {
                        input.addEventListener('input', function(e) {
                            let value = e.target.value.replace(/\D/g, '');
                            if (value === '') { e.target.value = '0,00'; return; }
                            if (value.length > 2) { const reais = value.slice(0, -2); const centavos = value.slice(-2); value = reais + ',' + centavos; }
                            else if (value.length === 2) value = '0,' + value;
                            else if (value.length === 1) value = '0,0' + value;
                            value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                            e.target.value = 'R$ ' + value;
                        });
                    });
                </script>

            <?php else: ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-cloud-upload-alt"></i> Importar Extrato Bancário</h1>
                    <div><a href="financeiro.php" class="btn btn-info"><i class="fas fa-chart-line"></i> Financeiro</a></div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> <strong>Como exportar do Nubank:</strong>
                    <ol style="margin-top:10px; margin-left:20px;"><li>Acesse o aplicativo do Nubank</li><li>Vá até a <strong>NuConta</strong> ou <strong>Cartão de Crédito</strong></li><li>Clique no ícone de <strong>compartilhar/exportar</strong> (3 pontinhos)</li><li>Selecione <strong>Exportar extrato</strong> e escolha o formato <strong>OFX</strong> (recomendado)</li><li>Envie o arquivo abaixo</li></ol>
                </div>

                <div class="card"><div class="card-header"><h3><i class="fas fa-upload"></i> Upload do Extrato</h3></div><div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="upload-area" id="upload-area"><i class="fas fa-file-invoice-dollar" style="font-size:48px; color:#6c757d; margin-bottom:15px;"></i><p><strong>Clique ou arraste o arquivo aqui</strong></p><p class="text-muted">Formatos: OFX, OFC, CSV</p><input type="file" name="arquivo_extrato" id="arquivo_extrato" accept=".ofx,.ofc,.csv" style="display:none;"><button type="button" class="btn btn-primary" onclick="document.getElementById('arquivo_extrato').click()"><i class="fas fa-folder-open"></i> Selecionar Arquivo</button></div>
                        <div id="file-info" style="margin-top:15px; display:none;"></div>
                        <div style="margin-top:30px; text-align:right;"><button type="submit" class="btn btn-success btn-lg" id="btn-submit" style="display:none;"><i class="fas fa-upload"></i> Processar Extrato</button></div>
                    </form>
                </div></div>

                <div class="card"><div class="card-header"><h3><i class="fas fa-lightbulb"></i> Como funciona</h3></div><div class="card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                        <div><strong>🤖 IA Inteligente:</strong><br>• Aprende com suas classificações anteriores<br>• Memoriza padrões de descrições bancárias<br>• Cada vez fica mais precisa</div>
                        <div><strong>📋 Múltiplos Orçamentos:</strong><br>• Um pagamento pode quitar vários orçamentos<br>• Controle de saldo pendente por orçamento<br>• Evita duplicidade de lançamentos</div>
                        <div><strong>💰 Controle Financeiro:</strong><br>• Categorias empresariais e pessoais<br>• Relatórios por período<br>• Gráficos de evolução mensal</div>
                    </div>
                </div></div>

                <script>
                    const uploadArea = document.getElementById('upload-area'), fileInput = document.getElementById('arquivo_extrato'), fileInfo = document.getElementById('file-info'), submitBtn = document.getElementById('btn-submit');
                    uploadArea.addEventListener('click', () => fileInput.click());
                    uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('drag-over'); });
                    uploadArea.addEventListener('dragleave', () => { uploadArea.classList.remove('drag-over'); });
                    uploadArea.addEventListener('drop', (e) => { e.preventDefault(); uploadArea.classList.remove('drag-over'); const file = e.dataTransfer.files[0]; if (file && (file.name.endsWith('.ofx') || file.name.endsWith('.ofc') || file.name.endsWith('.csv'))) { fileInput.files = e.dataTransfer.files; mostrarArquivo(file); } else { alert('Formato inválido'); } });
                    fileInput.addEventListener('change', (e) => { if (e.target.files.length > 0) mostrarArquivo(e.target.files[0]); });
                    function mostrarArquivo(file) { fileInfo.style.display = 'block'; fileInfo.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle"></i> Arquivo: <strong>${file.name}</strong> (${(file.size / 1024).toFixed(2)} KB)</div>`; submitBtn.style.display = 'inline-flex'; }
                </script>

            <?php endif; ?>
        </main>
    </div>
</body>
</html>