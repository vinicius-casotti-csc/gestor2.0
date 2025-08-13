<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogsEntrada;
use Illuminate\Http\Request;

class XmlController extends Controller
{
   public function transformarXml(Request $request)
   {
        // Lê o XML enviado no corpo da requisição
        $xmlString = $request->getContent();
        $oc = simplexml_load_string($xmlString);

        $ordem = $oc->Ordem_Compra;

        // Converte data/hora do XML para formato YYYY-MM-DD
        $dataEmissao = null;
        if (!empty((string) $ordem->Dt_Gravacao)) {
            $dataEmissao = \Carbon\Carbon::createFromFormat('d/m/Y H:i:s', trim((string) $ordem->Dt_Gravacao))
                                        ->format('Y-m-d');
        } else {
            $dataEmissao = now()->format('Y-m-d');
        }

        // Calcula valor total de todos os produtos
        $valorTotal = 0;
        foreach ($ordem->Produtos_Ordem_Compra->Produto_Ordem_Compra as $prod) {
            $preco = (float) str_replace(',', '.', $prod->Vl_Preco_Produto);
            $qtd   = (float) $prod->Qt_Produto;
            $valorTotal += $preco * $qtd;
        }

        //Consulta Centro de Custo RM
        $codRequisicao = intval($ordem->Cd_Requisicao);
        $codccusto = $this->xmlrequest($codRequisicao);
        $statusCode = $codccusto->getStatusCode();

        if($statusCode == 500){

            $msg = 'Não Integrado, Centro de custo não localizado!';

            $save = $this->saveLogs($ordem, $msg, 2);
            return response()->json(['Erro' => $msg], 500); 
        }

        // Criando XML final
        $novoXml = new \SimpleXMLElement('<root/>');

        // TMOV
        $tmov = $novoXml->addChild('TMOV');
        $tmov->addChild('CODCOLIGADA', '2');
        $tmov->addChild('IDMOV', '-1');
        $tmov->addChild('CODFILIAL', '1');
        $tmov->addChild('CODLOC', '21');
        $tmov->addChild('CODCFO', substr((string) $ordem->Cd_Fornecedor, -6)); // Exemplo de CFO
        $tmov->addChild('NUMEROMOV', '0');
        $tmov->addChild('SERIE', 'OC');
        $tmov->addChild('CODTMV', '1.1.04');
        $tmov->addChild('TIPO', 'A');
        $tmov->addChild('STATUS', 'A');
        $tmov->addChild('DATAEMISSAO', $dataEmissao);
        $tmov->addChild('CODCPG', '001');
        $tmov->addChild('VALORBRUTO', $valorTotal);
        $tmov->addChild('VALORLIQUIDO', $valorTotal);
        $tmov->addChild('VALORDESC', '0.0000');
        $tmov->addChild('VALORDESP', '0.0000');
        $tmov->addChild('DATAMOVIMENTO', $dataEmissao);
        $tmov->addChild('CODCOLCFO', '0');
        $tmov->addChild('IDMOVCFO', '-1');
        $tmov->addChild('VALORMERCADORIAS', '0.0000');
        $tmov->addChild('CODCCUSTO', '11.0015');

        // TMOVRATCCU
        $tmovratccu = $novoXml->addChild('TMOVRATCCU');
        $tmovratccu->addChild('CODCOLIGADA', '2');
        $tmovratccu->addChild('IDMOV', '-1');
        $tmovratccu->addChild('CODCCUSTO', '11.0015');
        $tmovratccu->addChild('VALOR', $valorTotal);
        $tmovratccu->addChild('IDMOVRATCCU', '-1');

        // Contador para sequências
        $seq = 1;

        // Loop para cada produto
        foreach ($ordem->Produtos_Ordem_Compra->Produto_Ordem_Compra as $prod) {

            //Consulta id produto no RM
            $codProduto = (string)$prod->Cd_Produto;
            $resultado = $this->consultaProduto($codProduto);

            if(empty($resultado) == 2){
                $msg = 'Não Integrado, Produto não cadastrado no RM!';
                $save = $this->saveLogs($ordem, $msg, 2);
                return response()->json(['Erro' => $msg], 500); 
            }
            
            $preco = (float) str_replace(',', '.', $prod->Vl_Preco_Produto);
            $qtd   = (float) $prod->Qt_Produto;
            $valorBruto = $preco * $qtd;

            // TITMMOV
            $titmmov = $novoXml->addChild('TITMMOV');
            $titmmov->addChild('CODCOLIGADA', '2');
            $titmmov->addChild('IDMOV', '-1');
            $titmmov->addChild('NSEQITMMOV', $seq);
            $titmmov->addChild('CODFILIAL', '1');
            $titmmov->addChild('NUMEROSEQUENCIAL', $seq);
            $titmmov->addChild('IDPRD', $resultado[0]['IDPRD']);
            $titmmov->addChild('QUANTIDADE', $qtd);
            $titmmov->addChild('PRECOUNITARIO', $preco);
            $titmmov->addChild('VALORDESP', '0.0000');
            $titmmov->addChild('VALORDESC', '0.0000');
            $titmmov->addChild('CODUND', (string) $prod->Ds_Unidade_Compra);
            $titmmov->addChild('VALORBRUTOITEM', $valorBruto);
            $titmmov->addChild('VALORTOTALITEM', $valorBruto);
            $titmmov->addChild('VALORLIQUIDO', $valorBruto);
            $titmmov->addChild('QUANTIDADETOTAL', $qtd);
            $titmmov->addChild('INTEGRAAPLICACAO', 'T');
            $titmmov->addChild('CODCCUSTO', $codccusto->getData()->codccusto);

            // TITMMOVRATCCU
            $titmmovratccu = $novoXml->addChild('TITMMOVRATCCU');
            $titmmovratccu->addChild('CODCOLIGADA', '2');
            $titmmovratccu->addChild('IDMOV', '-1');
            $titmmovratccu->addChild('CODCCUSTO', $codccusto->getData()->codccusto);
            $titmmovratccu->addChild('VALOR', $valorBruto);
            $titmmovratccu->addChild('IDMOVRATCCU', '-1');

            $seq++;
        }

        $retorno = $this->savePedRM($novoXml);
    
        $xml = simplexml_load_string($retorno);
        $namespaces = $xml->getNamespaces(true);
        $body = $xml->children($namespaces['s'])->Body;
        $response = $body->children('http://www.totvs.com/')->SaveRecordResponse;
        $result = (string) $response->SaveRecordResult;

        
        $msg = 'Integracao realizada com sucesso!';
            
        $save = $this->saveLogs($ordem, $msg, 1);
        return response()->json(['Sucesso' => $msg], 200); 
        
    } 

    public function xmlrequest($codRequisicao)
    {
        $xml = <<<XML
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tot="http://www.totvs.com/">
            <soapenv:Header/>
            <soapenv:Body>
                <tot:ReadRecord>
                    <tot:DataServerName>MovMovimentoTBCData</tot:DataServerName>
                    <tot:PrimaryKey>2;$codRequisicao</tot:PrimaryKey>
                    <tot:Contexto>codcoligada=2;codusuario=Bionexo;codsistema=O</tot:Contexto>
                </tot:ReadRecord>
            </soapenv:Body>
            </soapenv:Envelope>
        XML;

        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->post('https://alvorecerassociacao185174.rm.cloudtotvs.com.br:8051/wsDataServer/IwsDataServer', [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => 'http://www.totvs.com/IwsDataServer/ReadRecord',
                ],
                'body' => $xml,
                'verify' => false,
                'auth' => ['Bionexo', 'Bionexo@2025']
            ]);

            $body = $response->getBody()->getContents();
            
            $soap = simplexml_load_string($body);

            $soap->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
            $soap->registerXPathNamespace('ns', 'http://www.totvs.com/');

            $resultNode = $soap->xpath('//ns:ReadRecordResult')[0] ?? null;

            if (!$resultNode) {
                return response()->json(['error' => 'Resultado não encontrado'], 500);
            }

            $decodedXml = html_entity_decode((string) $resultNode);
            $realXml = simplexml_load_string($decodedXml);
            $codccusto = (string) $realXml->TMOV->CODCCUSTO;

            return response()->json([
                'codccusto' => $codccusto,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function consultaProduto($codProduto)
    {
        $client = new \GuzzleHttp\Client();
        
        try {
            $url = "https://alvorecerassociacao185174.rm.cloudtotvs.com.br:8051/api/framework/v1/consultaSQLServer/RealizaConsulta/BIONEXO_PRODUTO/2/O";
            $parameters = [
                'parameters' => 'CODIGOPRD=40020659'//.$codProduto
            ];

            $response = $client->get($url, [
                'query' => $parameters,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'verify' => false,
                'auth' => ['Bionexo', 'Bionexo@2025'] 
            ]);

            $body = $response->getBody()->getContents();

            $result = json_decode($body, true); 
            
            return $result;

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function savePedRM($novoXml)
    {
        $xmlPayload = $novoXml->asXML(); // XML completo do movimento

        $soapXml = <<<XML
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tot="http://www.totvs.com/">
                <soapenv:Header/>
                <soapenv:Body>
                    <tot:SaveRecord>
                        <tot:DataServerName>MovMovimentoTBCData</tot:DataServerName>
                        <tot:XML><![CDATA[$xmlPayload]]></tot:XML>
                        <tot:Contexto>CODCOLIGADA=2;CODUSUARIO=bionexo;CODSISTEMA=T</tot:Contexto>
                    </tot:SaveRecord>
                </soapenv:Body>
            </soapenv:Envelope>
            XML;

        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->post('https://alvorecerassociacao185174.rm.cloudtotvs.com.br:8051/wsDataServer/IwsDataServer', [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => 'http://www.totvs.com/IwsDataServer/SaveRecord',
                ],
                'body' => $soapXml,
                'verify' => false,
                'auth' => ['Bionexo', 'Bionexo@2025']
            ]);

            $body = $response->getBody()->getContents();

            return $body;

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function saveLogs($dados, $motivo, $status)
    {

        $insert = LogsEntrada::create([
                    'id_requisicao' => $dados->Cd_Requisicao,
                    'json'          => json_encode($dados, JSON_UNESCAPED_UNICODE),
                    'data'          => now(),
                    'motivo'        => $motivo,
                    'status'        => $status,
                ]);

        return $insert;

    }

}