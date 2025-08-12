<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
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
    $tmov->addChild('VALORBRUTO', number_format($valorTotal, 4, '.', ''));
    $tmov->addChild('VALORLIQUIDO', number_format($valorTotal, 4, '.', ''));
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
    $tmovratccu->addChild('VALOR', number_format($valorTotal, 4, '.', ''));
    $tmovratccu->addChild('IDMOVRATCCU', '-1');

    // Contador para sequências
    $seq = 1;

    // Loop para cada produto
    foreach ($ordem->Produtos_Ordem_Compra->Produto_Ordem_Compra as $prod) {
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
        $titmmov->addChild('IDPRD', (string) $prod->Cd_Produto);
        $titmmov->addChild('QUANTIDADE', number_format($qtd, 4, '.', ''));
        $titmmov->addChild('PRECOUNITARIO', number_format($preco, 4, '.', ''));
        $titmmov->addChild('VALORDESP', '0.0000');
        $titmmov->addChild('VALORDESC', '0.0000');
        $titmmov->addChild('CODUND', (string) $prod->Ds_Unidade_Compra);
        $titmmov->addChild('VALORBRUTOITEM', number_format($valorBruto, 4, '.', ''));
        $titmmov->addChild('VALORTOTALITEM', number_format($valorBruto, 4, '.', ''));
        $titmmov->addChild('VALORLIQUIDO', number_format($valorBruto, 4, '.', ''));
        $titmmov->addChild('QUANTIDADETOTAL', number_format($qtd, 4, '.', ''));
        $titmmov->addChild('INTEGRAAPLICACAO', 'T');
        $titmmov->addChild('CODCCUSTO', '11.0015');

        // TITMMOVRATCCU
        $titmmovratccu = $novoXml->addChild('TITMMOVRATCCU');
        $titmmovratccu->addChild('CODCOLIGADA', '2');
        $titmmovratccu->addChild('IDMOV', '-1');
        $titmmovratccu->addChild('CODCCUSTO', '11.0015');
        $titmmovratccu->addChild('VALOR', number_format($valorBruto, 4, '.', ''));
        $titmmovratccu->addChild('IDMOVRATCCU', '-1');

        $seq++;
    }

    // Retorna o XML final
    return response($novoXml->asXML(), 200)
        ->header('Content-Type', 'application/xml');
    } 

    public function xmlrequest()
    {
        $xml = <<<XML
    <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tot="http://www.totvs.com/">
    <soapenv:Header/>
    <soapenv:Body>
        <tot:ReadRecord>
            <tot:DataServerName>MovMovimentoTBCData</tot:DataServerName>
            <tot:PrimaryKey>2;2009357</tot:PrimaryKey>
            <tot:Contexto>codcoligada=2;codusuario=vinicius.casotti;codsistema=O</tot:Contexto>
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
                'auth' => ['vinicius.casotti', 'Dregon123']
            ]);

            $body = $response->getBody()->getContents();

            // Carrega o XML SOAP
            $soap = simplexml_load_string($body);

            // Define namespaces e pega só o ReadRecordResult
            $soap->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
            $soap->registerXPathNamespace('ns', 'http://www.totvs.com/');

            $resultNode = $soap->xpath('//ns:ReadRecordResult')[0] ?? null;

            if (!$resultNode) {
                return response()->json(['error' => 'Resultado não encontrado'], 500);
            }

            // Decodifica as entidades HTML (&lt; &gt; &amp;)
            $decodedXml = html_entity_decode((string) $resultNode);

            // Agora carrega como XML real
            $realXml = simplexml_load_string($decodedXml);

            // Aqui você já pode acessar, por exemplo, o CODCCUSTO
            $codccusto = (string) $realXml->TMOV->CODCCUSTO;

            return response()->json([
                'codccusto' => $codccusto,
                'xml' => $realXml
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

}