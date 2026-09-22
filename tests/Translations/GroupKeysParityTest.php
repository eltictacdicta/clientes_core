<?php
/**
 * Translation parity for the mandatory discount-group copy.
 *
 * `view/ventas_cliente.html.twig` already references `grupo-descuentos` and
 * `seleccione-grupo-descuentos`, and the mandatory-group flow adds
 * `grupo-descuentos-obligatorio`. None of the three was defined in either
 * locale before this change (validator finding VF-1), so this suite pins them
 * in both `en` and `es` with the copy from design §15.6.
 *
 * The empty option key `sin-grupo-descuentos` is removed by design §15.6 and
 * must not be reintroduced.
 */

declare(strict_types=1);

namespace Tests\ClientesCore\Translations;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class GroupKeysParityTest extends TestCase
{
    /**
     * Keys that must exist in every locale, with their mandated copy.
     *
     * @var array<string, array{en: string, es: string}>
     */
    private const REQUIRED_KEYS = [
        'grupo-descuentos' => [
            'en' => 'Discount group',
            'es' => 'Grupo de descuentos',
        ],
        'grupo-descuentos-obligatorio' => [
            'en' => 'You must select a discount group to save.',
            'es' => 'Debe seleccionar un grupo de descuentos para guardar.',
        ],
        'seleccione-grupo-descuentos' => [
            'en' => 'Select a discount group',
            'es' => 'Seleccione un grupo de descuentos',
        ],
    ];

    /**
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        $path = dirname(__DIR__, 2) . '/translations/messages.' . $locale . '.yaml';
        self::assertFileExists($path);

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed;
    }

    public function testBothLocalesDefineTheDiscountGroupCopy(): void
    {
        foreach (['en', 'es'] as $locale) {
            $messages = $this->translations($locale);

            foreach (self::REQUIRED_KEYS as $key => $copy) {
                self::assertArrayHasKey($key, $messages, "$locale is missing '$key'");
                self::assertSame(
                    $copy[$locale],
                    $messages[$key],
                    "$locale has the wrong copy for '$key'"
                );
            }
        }
    }

    public function testRemovedEmptyOptionKeyIsNotReintroduced(): void
    {
        foreach (['en', 'es'] as $locale) {
            $messages = $this->translations($locale);

            self::assertArrayNotHasKey(
                'sin-grupo-descuentos',
                $messages,
                "$locale must not define the removed discount-group empty option"
            );
        }
    }
}
