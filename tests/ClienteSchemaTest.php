<?php
declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

/**
 * Guard for the explicit non-change of model/table/clientes.xml (Q3, R1).
 *
 * The mandatory discount group is enforced in cliente::test() only. The
 * database column stays nullable and no foreign key is added, so
 * PluginSchemaSynchronizer::synchronize() cannot apply a constraint that
 * contradicts on-disk data before Init::upgrade() runs.
 */
final class ClienteSchemaTest extends TestCase
{
    private \SimpleXMLElement $xml;

    protected function setUp(): void
    {
        parent::setUp();

        $path = FS_FOLDER . '/plugins/clientes_core/model/table/clientes.xml';
        $this->assertFileExists($path, 'clientes.xml must exist and stay untouched');

        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, 'clientes.xml must be valid XML');

        $this->xml = $xml;
    }

    public function testDiscountGroupColumnStaysNullable(): void
    {
        $nulo = null;
        foreach ($this->xml->columna as $columna) {
            if ((string) $columna->nombre === 'codgrupo_descuento') {
                $nulo = (string) $columna->nulo;
                break;
            }
        }

        $this->assertSame(
            'YES',
            $nulo,
            'codgrupo_descuento must stay nullable: the invariant lives in cliente::test() (Q3)'
        );
    }

    public function testNoConstraintReferencesTheDiscountGroupColumn(): void
    {
        foreach ($this->xml->restriccion as $restriccion) {
            $this->assertStringNotContainsString(
                'codgrupo_descuento',
                (string) $restriccion->consulta,
                'No FK or constraint may be added on codgrupo_descuento (Q3)'
            );
        }
    }

    public function testClientGroupForeignKeyIsUnchanged(): void
    {
        $found = false;
        foreach ($this->xml->restriccion as $restriccion) {
            if ((string) $restriccion->nombre === 'ca_clientes_grupos') {
                $found = true;
                $this->assertStringContainsString(
                    'FOREIGN KEY (codgrupo)',
                    (string) $restriccion->consulta,
                    'ca_clientes_grupos must keep its client-group foreign key'
                );
            }
        }

        $this->assertTrue($found, 'ca_clientes_grupos must remain declared in clientes.xml');
    }
}
