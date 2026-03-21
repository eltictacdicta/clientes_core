<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2017 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\model;

/**
 * El cliente. Puede tener una o varias direcciones y subcuentas asociadas.
 * 
 * Este modelo se centra en el dominio de terceros: identidad, datos fiscales,
 * comerciales, direcciones y pertenencia a grupos. La lógica contable
 * (subcuentas) y la relación con proveedor se mantienen como integraciones
 * opcionales en facturacion_base.
 */
class cliente extends \fs_model
{
    private const COMMERCIAL_FIELDS = [
        'codserie',
        'coddivisa',
        'codpago',
        'codagente',
        'codproveedor',
        'codtarifa',
    ];

    /**
     * Clave primaria. Varchar (6).
     * @var string 
     */
    public $codcliente;

    /**
     * Nombre por el que conocemos al cliente, no necesariamente el oficial.
     * @var string 
     */
    public $nombre;

    /**
     * Razón social del cliente, es decir, el nombre oficial. El que aparece en las facturas.
     * @var string
     */
    public $razonsocial;

    /**
     * Tipo de identificador fiscal del cliente.
     * Ejemplos: CIF, NIF, CUIT...
     * @var string 
     */
    public $tipoidfiscal;

    /**
     * Identificador fiscal del cliente.
     * @var string 
     */
    public $cifnif;
    public $telefono1;
    public $telefono2;
    public $fax;
    public $email;
    public $web;

    /**
     * Serie predeterminada para este cliente.
     * @var string 
     */
    public $codserie;

    /**
     * Divisa predeterminada para este cliente.
     * @var string 
     */
    public $coddivisa;

    /**
     * Forma de pago predeterminada para este cliente.
     * @var string 
     */
    public $codpago;

    /**
     * Empleado/agente asignado al cliente.
     * @var string 
     */
    public $codagente;

    /**
     * Grupo al que pertenece el cliente.
     * @var string 
     */
    public $codgrupo;

    /**
     * TRUE -> el cliente ya no nos compra o no queremos nada con él.
     * @var boolean 
     */
    public $debaja;

    /**
     * Fecha en la que se dió de baja al cliente.
     * @var string 
     */
    public $fechabaja;

    /**
     * Fecha en la que se dió de alta al cliente.
     * @var string 
     */
    public $fechaalta;
    public $observaciones;

    /**
     * Régimen de fiscalidad del cliente. Por ahora solo están implementados
     * general y exento.
     * @var string 
     */
    public $regimeniva;

    /**
     * TRUE -> al cliente se le aplica recargo de equivalencia.
     * @var boolean 
     */
    public $recargo;

    /**
     * TRUE  -> el cliente es una persona física.
     * FALSE -> el cliente es una persona jurídica (empresa).
     * @var boolean 
     */
    public $personafisica;

    /**
     * Dias de pago preferidos a la hora de calcular el vencimiento de las facturas.
     * Días separados por comas: 1,15,31
     * @var string 
     */
    public $diaspago;

    /**
     * Proveedor asociado equivalente.
     * Integración opcional: solo se usa si el plugin de facturación está presente.
     * @var string
     */
    public $codproveedor;

    /**
     * Código de la tarifa para este cliente
     * @var string
     */
    public $codtarifa;

    private static $regimenes_iva;

    public function __construct($data = FALSE)
    {
        parent::__construct('clientes');
        if ($data) {
            $this->codcliente = $data['codcliente'];
            $this->nombre = $data['nombre'];

            if (is_null($data['razonsocial'])) {
                $this->razonsocial = $data['nombrecomercial'] ?? $data['nombre'];
            } else {
                $this->razonsocial = $data['razonsocial'];
            }

            $this->tipoidfiscal = $data['tipoidfiscal'];
            $this->cifnif = $data['cifnif'];
            $this->telefono1 = $data['telefono1'];
            $this->telefono2 = $data['telefono2'];
            $this->fax = $data['fax'];
            $this->email = $data['email'];
            $this->web = $data['web'];
            $this->codserie = $data['codserie'];
            $this->coddivisa = $data['coddivisa'];
            $this->codpago = $data['codpago'];
            $this->codagente = $data['codagente'];
            $this->codgrupo = $data['codgrupo'];
            $this->debaja = $this->str2bool($data['debaja']);

            $this->fechabaja = NULL;
            if ($data['fechabaja']) {
                $this->fechabaja = date('d-m-Y', strtotime($data['fechabaja']));
            }

            $this->fechaalta = date('d-m-Y', strtotime($data['fechaalta']));
            $this->observaciones = $this->no_html($data['observaciones']);
            $this->regimeniva = $data['regimeniva'];
            $this->recargo = $this->str2bool($data['recargo']);
            $this->personafisica = $this->str2bool($data['personafisica']);
            $this->diaspago = $data['diaspago'];
            $this->codproveedor = $data['codproveedor'];
            $this->codtarifa = $data['codtarifa'];
        } else {
            $this->codcliente = NULL;
            $this->nombre = '';
            $this->razonsocial = '';
            $this->tipoidfiscal = FS_CIFNIF;
            $this->cifnif = '';
            $this->telefono1 = '';
            $this->telefono2 = '';
            $this->fax = '';
            $this->email = '';
            $this->web = '';
            $this->codserie = NULL;
            $this->coddivisa = $this->default_items->coddivisa();
            $this->codpago = $this->default_items->codpago();
            $this->codagente = NULL;
            $this->codgrupo = NULL;
            $this->debaja = FALSE;
            $this->fechabaja = NULL;
            $this->fechaalta = date('d-m-Y');
            $this->observaciones = NULL;
            $this->regimeniva = 'General';
            $this->recargo = FALSE;
            $this->personafisica = TRUE;
            $this->diaspago = NULL;
            $this->codproveedor = NULL;
            $this->codtarifa = NULL;
        }

        $this->load_commercial_extension();
    }

    protected function install()
    {
        $this->clean_cache();
        $grupo = new \grupo_clientes();
        // La instancia se crea para asegurar la carga de la clase y su tabla

        return '';
    }

    public function observaciones_resume()
    {
        if ($this->observaciones == '') {
            return '-';
        } else if (strlen($this->observaciones) < 60) {
            return $this->observaciones;
        }

        return substr($this->observaciones, 0, 50) . '...';
    }

    public function url()
    {
        if (is_null($this->codcliente)) {
            return "index.php?page=ventas_clientes";
        }

        return "index.php?page=ventas_cliente&cod=" . $this->codcliente;
    }

    /**
     * @deprecated since version 50
     * @return boolean
     */
    public function is_default()
    {
        return FALSE;
    }

    /**
     * Devuelve un array con los regimenes de iva disponibles.
     * @return array
     */
    public function regimenes_iva()
    {
        if (!isset(self::$regimenes_iva)) {
            if (class_exists('fs_var')) {
                $fsvar = new \fs_var();
                $data = $fsvar->simple_get('cliente::regimenes_iva');
                if ($data) {
                    self::$regimenes_iva = array();
                    foreach (explode(',', $data) as $d) {
                        self::$regimenes_iva[] = trim($d);
                    }
                } else {
                    self::$regimenes_iva = array('General', 'Exento');
                }
            } else {
                self::$regimenes_iva = array('General', 'Exento');
            }

            $data = $this->db->select("SELECT DISTINCT regimeniva FROM clientes ORDER BY regimeniva ASC;");
            if ($data) {
                foreach ($data as $d) {
                    if (!in_array($d['regimeniva'], self::$regimenes_iva)) {
                        self::$regimenes_iva[] = $d['regimeniva'];
                    }
                }
            }
        }

        return self::$regimenes_iva;
    }

    /**
     * @param string $cod
     * @return \cliente|boolean
     */
    public function get($cod)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codcliente = " . $this->var2str($cod) . ";");
        if ($data) {
            return new \cliente($data[0]);
        }

        return FALSE;
    }

    /**
     * @param string $cifnif
     * @param string $razon
     * @return boolean|\cliente
     */
    public function get_by_cifnif($cifnif, $razon = FALSE)
    {
        if ($cifnif == '' && $razon) {
            $razon = $this->no_html(mb_strtolower($razon, 'UTF8'));
            $sql = "SELECT * FROM " . $this->table_name . " WHERE cifnif = '' AND lower(razonsocial) = " . $this->var2str($razon) . ";";
        } else {
            $cifnif = mb_strtolower($cifnif, 'UTF8');
            $sql = "SELECT * FROM " . $this->table_name . " WHERE lower(cifnif) = " . $this->var2str($cifnif) . ";";
        }

        $data = $this->db->select($sql);
        if ($data) {
            return new \cliente($data[0]);
        }

        return FALSE;
    }

    /**
     * @param string $email
     * @return boolean|\cliente
     */
    public function get_by_email($email)
    {
        $email = mb_strtolower($email, 'UTF8');
        $sql = "SELECT * FROM " . $this->table_name . " WHERE lower(email) = " . $this->var2str($email) . ";";

        $data = $this->db->select($sql);
        if ($data) {
            return new \cliente($data[0]);
        }

        return FALSE;
    }

    /**
     * @return \direccion_cliente[]
     */
    public function get_direcciones()
    {
        $dir = new \direccion_cliente();
        return $dir->all_from_cliente($this->codcliente);
    }

    public function exists()
    {
        if (is_null($this->codcliente)) {
            return FALSE;
        }

        return $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codcliente = " . $this->var2str($this->codcliente) . ";");
    }

    /**
     * @return string
     */
    public function get_new_codigo()
    {
        $data = $this->db->select("SELECT MAX(" . $this->db->sql_to_int('codcliente') . ") as cod FROM " . $this->table_name . ";");
        if ($data) {
            return sprintf('%06s', (1 + intval($data[0]['cod'])));
        }

        return '000001';
    }

    public function test()
    {
        $status = FALSE;

        if (is_null($this->codcliente)) {
            $this->codcliente = $this->get_new_codigo();
        } else {
            $this->codcliente = trim($this->codcliente);
        }

        $this->nombre = $this->no_html($this->nombre);
        $this->razonsocial = $this->no_html($this->razonsocial);
        $this->cifnif = $this->no_html($this->cifnif);
        $this->observaciones = $this->no_html($this->observaciones);

        if ($this->debaja) {
            if (is_null($this->fechabaja)) {
                $this->fechabaja = date('d-m-Y');
            }
        } else {
            $this->fechabaja = NULL;
        }

        $array_dias = array();
            $diaspago = trim((string) ($this->diaspago ?? ''));
            if ($diaspago !== '') {
                foreach (str_getcsv($diaspago) as $d) {
                    if (intval($d) >= 1 && intval($d) <= 31) {
                        $array_dias[] = intval($d);
                    }
            }
        }
        $this->diaspago = NULL;
        if (!empty($array_dias)) {
            $this->diaspago = join(',', $array_dias);
        }

        if (!preg_match("/^[A-Z0-9]{1,6}$/i", $this->codcliente)) {
            $this->new_error_msg("Código de cliente no válido: " . $this->codcliente);
        } else if (strlen($this->nombre) < 1 || strlen($this->nombre) > 100) {
            $this->new_error_msg("Nombre de cliente no válido: " . $this->nombre);
        } else if (strlen($this->razonsocial) > 100) {
            $this->new_error_msg("Razón social del cliente no válida: " . $this->razonsocial);
        } else {
            $status = TRUE;
        }

        return $status;
    }

    public function save()
    {
        if ($this->test()) {
            $this->clean_cache();
            $use_extension = $this->has_commercial_extension();

            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET nombre = " . $this->var2str($this->nombre)
                    . ", razonsocial = " . $this->var2str($this->razonsocial)
                    . ", tipoidfiscal = " . $this->var2str($this->tipoidfiscal)
                    . ", cifnif = " . $this->var2str($this->cifnif)
                    . ", telefono1 = " . $this->var2str($this->telefono1)
                    . ", telefono2 = " . $this->var2str($this->telefono2)
                    . ", fax = " . $this->var2str($this->fax)
                    . ", email = " . $this->var2str($this->email)
                    . ", web = " . $this->var2str($this->web)
                    . ", codgrupo = " . $this->var2str($this->codgrupo)
                    . ", debaja = " . $this->var2str($this->debaja)
                    . ", fechabaja = " . $this->var2str($this->fechabaja)
                    . ", fechaalta = " . $this->var2str($this->fechaalta)
                    . ", observaciones = " . $this->var2str($this->observaciones)
                    . ", regimeniva = " . $this->var2str($this->regimeniva)
                    . ", recargo = " . $this->var2str($this->recargo)
                    . ", personafisica = " . $this->var2str($this->personafisica)
                    . ", diaspago = " . $this->var2str($this->diaspago);

                if (!$use_extension) {
                    $sql .= ", codserie = " . $this->var2str($this->codserie)
                        . ", coddivisa = " . $this->var2str($this->coddivisa)
                        . ", codpago = " . $this->var2str($this->codpago)
                        . ", codagente = " . $this->var2str($this->codagente)
                        . ", codproveedor = " . $this->var2str($this->codproveedor)
                        . ", codtarifa = " . $this->var2str($this->codtarifa);
                }

                $sql .= "  WHERE codcliente = " . $this->var2str($this->codcliente) . ";";
            } else {
                $columns = "codcliente,nombre,razonsocial,tipoidfiscal,cifnif,telefono1,telefono2,fax,email,web,codgrupo,debaja,fechabaja,fechaalta,observaciones,regimeniva,recargo,personafisica,diaspago";
                $values = $this->var2str($this->codcliente)
                    . "," . $this->var2str($this->nombre)
                    . "," . $this->var2str($this->razonsocial)
                    . "," . $this->var2str($this->tipoidfiscal)
                    . "," . $this->var2str($this->cifnif)
                    . "," . $this->var2str($this->telefono1)
                    . "," . $this->var2str($this->telefono2)
                    . "," . $this->var2str($this->fax)
                    . "," . $this->var2str($this->email)
                    . "," . $this->var2str($this->web)
                    . "," . $this->var2str($this->codgrupo)
                    . "," . $this->var2str($this->debaja)
                    . "," . $this->var2str($this->fechabaja)
                    . "," . $this->var2str($this->fechaalta)
                    . "," . $this->var2str($this->observaciones)
                    . "," . $this->var2str($this->regimeniva)
                    . "," . $this->var2str($this->recargo)
                    . "," . $this->var2str($this->personafisica)
                    . "," . $this->var2str($this->diaspago);

                if (!$use_extension) {
                    $columns .= ",codserie,coddivisa,codpago,codagente,codproveedor,codtarifa";
                    $values .= "," . $this->var2str($this->codserie)
                        . "," . $this->var2str($this->coddivisa)
                        . "," . $this->var2str($this->codpago)
                        . "," . $this->var2str($this->codagente)
                        . "," . $this->var2str($this->codproveedor)
                        . "," . $this->var2str($this->codtarifa);
                }

                $sql = "INSERT INTO " . $this->table_name . " (" . $columns . ") VALUES (" . $values . ");";
            }

            if (!$this->db->exec($sql)) {
                return FALSE;
            }

            return $use_extension ? $this->save_commercial_extension() : TRUE;
        }

        return FALSE;
    }

    public function delete()
    {
        $this->clean_cache();
        $result = $this->db->exec("DELETE FROM " . $this->table_name . " WHERE codcliente = " . $this->var2str($this->codcliente) . ";");
        if ($result && $this->has_commercial_extension()) {
            $this->delete_commercial_extension();
        }

        return $result;
    }

    private function has_commercial_extension()
    {
        return class_exists('cliente_facturacion');
    }

    private function load_commercial_extension()
    {
        if (empty($this->codcliente) || !$this->has_commercial_extension()) {
            return;
        }

        $extensionModel = new \cliente_facturacion();
        $extension = $extensionModel->get($this->codcliente);
        if (!$extension) {
            return;
        }

        foreach (self::COMMERCIAL_FIELDS as $field) {
            $this->{$field} = $extension->{$field};
        }
    }

    private function save_commercial_extension()
    {
        $extensionModel = new \cliente_facturacion();
        $extension = $extensionModel->get($this->codcliente);
        if (!$extension) {
            $extension = new \cliente_facturacion();
            $extension->codcliente = $this->codcliente;
        }

        foreach (self::COMMERCIAL_FIELDS as $field) {
            $extension->{$field} = $this->{$field};
        }

        return $extension->save();
    }

    private function delete_commercial_extension()
    {
        $extensionModel = new \cliente_facturacion();
        $extension = $extensionModel->get($this->codcliente);
        if ($extension) {
            $extension->delete();
        }
    }

    private function clean_cache()
    {
        $this->cache->delete('m_cliente_all');
    }

    public function all($offset = 0)
    {
        $data = $this->db->select_limit("SELECT * FROM " . $this->table_name . " ORDER BY lower(nombre) ASC", FS_ITEM_LIMIT, $offset);
        return $this->all_from_data($data);
    }

    /**
     * @return \cliente[]
     */
    public function all_full()
    {
        $clientlist = $this->cache->get_array('m_cliente_all');
        if (!$clientlist) {
            $data = $this->db->select("SELECT * FROM " . $this->table_name . " ORDER BY lower(nombre) ASC;");
            $clientlist = $this->all_from_data($data);
            $this->cache->set('m_cliente_all', $clientlist);
        }

        return $clientlist;
    }

    public function search($query, $offset = 0)
    {
        $query = mb_strtolower($this->no_html($query), 'UTF8');

        $consulta = "SELECT * FROM " . $this->table_name . " WHERE debaja = FALSE AND ";
        if (is_numeric($query)) {
            $consulta .= "(nombre LIKE '%" . $query . "%' OR razonsocial LIKE '%" . $query . "%'"
                . " OR codcliente LIKE '%" . $query . "%' OR cifnif LIKE '%" . $query . "%'"
                . " OR telefono1 LIKE '" . $query . "%' OR telefono2 LIKE '" . $query . "%'"
                . " OR observaciones LIKE '%" . $query . "%')";
        } else {
            $buscar = str_replace(' ', '%', $query);
            $consulta .= "(lower(nombre) LIKE '%" . $buscar . "%' OR lower(razonsocial) LIKE '%" . $buscar . "%'"
                . " OR lower(cifnif) LIKE '%" . $buscar . "%' OR lower(observaciones) LIKE '%" . $buscar . "%'"
                . " OR lower(email) LIKE '%" . $buscar . "%')";
        }
        $consulta .= " ORDER BY lower(nombre) ASC";

        $data = $this->db->select_limit($consulta, FS_ITEM_LIMIT, $offset);
        return $this->all_from_data($data);
    }

    /**
     * @param string $dni
     * @param integer $offset
     * @return \cliente[]
     */
    public function search_by_dni($dni, $offset = 0)
    {
        $query = mb_strtolower($this->no_html($dni), 'UTF8');
        $consulta = "SELECT * FROM " . $this->table_name . " WHERE debaja = FALSE "
            . "AND lower(cifnif) LIKE '" . $query . "%' ORDER BY lower(nombre) ASC";

        $data = $this->db->select_limit($consulta, FS_ITEM_LIMIT, $offset);
        return $this->all_from_data($data);
    }

    private function all_from_data(&$data)
    {
        $clilist = array();
        if ($data) {
            foreach ($data as $d) {
                $clilist[] = new \cliente($d);
            }
        }

        return $clilist;
    }

    /**
     * Correcciones de integridad de la tabla.
     * Solo corrige relaciones propias del dominio de terceros (grupos).
     * Las correcciones de proveedor se delegan a facturacion_base.
     */
    public function fix_db()
    {
        $this->db->exec("UPDATE " . $this->table_name . " SET debaja = false WHERE debaja IS NULL;");

        $this->db->exec("UPDATE " . $this->table_name . " SET codgrupo = NULL WHERE codgrupo IS NOT NULL"
            . " AND codgrupo NOT IN (SELECT codgrupo FROM gruposclientes);");
    }
}
