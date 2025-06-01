<?php // ### conexao.php (REVISTO) ###

// Configurações do Banco de Dados Local (Padrão XAMPP/WAMP)
$host = 'localhost';       // Servidor do banco de dados
$dbname = 'bar_sistema'; // Nome do banco de dados (NOVO NOME)
$user = 'root';            // Usuário do banco de dados
$pass = '';                // Senha do banco de dados (geralmente vazia no XAMPP/WAMP)
$charset = 'utf8mb4';      // Charset da conexão

// --- NÃO ALTERAR ABAIXO DESTA LINHA (a menos que saiba o que está fazendo) ---

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em erros
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna arrays associativos
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa prepared statements nativos
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Em ambiente de produção, logar o erro em vez de exibi-lo diretamente
    error_log("Erro de Conexão com o Banco de Dados: " . $e->getMessage());

    // Resposta genérica para o cliente em caso de falha de conexão
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'error' => 'Falha na conexão com o servidor de dados. Tente novamente mais tarde.'
    ]);
    exit; // Interrompe a execução do script se não conseguir conectar
}

// O objeto $pdo está pronto para ser usado nos scripts que incluírem este arquivo.
?>
