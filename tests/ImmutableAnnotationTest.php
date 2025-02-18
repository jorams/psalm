<?php
namespace Psalm\Tests;

use const DIRECTORY_SEPARATOR;

class ImmutableAnnotationTest extends TestCase
{
    use Traits\InvalidCodeAnalysisTestTrait;
    use Traits\ValidCodeAnalysisTestTrait;

    /**
     * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
     */
    public function providerValidCodeParse()
    {
        return [
            'immutableClassGenerating' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class A {
                        /** @var int */
                        private $a;

                        /** @var string */
                        public $b;

                        public function __construct(int $a, string $b) {
                            $this->a = $a;
                            $this->b = $b;
                        }

                        public function setA(int $a) : self {
                            return new self($a, $this->b);
                        }
                    }',
            ],
            'callInternalClassMethod' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class A {
                        /** @var string */
                        public $a;

                        public function __construct(string $a) {
                            $this->a = $a;
                        }

                        public function getA() : string {
                            return $this->a;
                        }

                        public function getHelloA() : string {
                            return "hello" . $this->getA();
                        }
                    }',
            ],
            'addToCart' => [
                '<?php
                    /** @psalm-immutable */
                    class Cart {
                        /** @var CartItem[] */
                        public array $items;

                        /** @param CartItem[] $items */
                        public function __construct(array $items) {
                            $this->items = $items;
                        }

                        public function addItem(CartItem $item) : self {
                            $items = $this->items;
                            $items[] = $item;
                            return new Cart($items);
                        }
                    }

                    /** @psalm-immutable */
                    class CartItem {
                        public string $name;
                        public float $price;

                        public function __construct(string $name, float $price) {
                            $this->name = $name;
                            $this->price = $price;
                        }
                    }

                    /** @psalm-pure */
                    function addItemToCart(Cart $c, string $name, float $price) : Cart {
                        return $c->addItem(new CartItem($name, $price));
                    }',
            ],
            'allowImpureStaticMethod' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    final class ClientId
                    {
                        public string $id;

                        private function __construct(string $id)
                        {
                            $this->id = $id;
                        }

                        public static function fromString(string $id): self
                        {
                            return new self($id . rand(0, 1));
                        }
                    }'
            ],
            'allowPropertySetOnNewInstance' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class Foo {
                        protected string $bar;

                        public function __construct(string $bar) {
                            $this->bar = $bar;
                        }

                        public function withBar(string $bar): self {
                            $new = new Foo("hello");
                            /** @psalm-suppress InaccessibleProperty */
                            $new->bar = $bar;

                            return $new;
                        }
                    }'
            ],
            'allowClone' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class Foo {
                        protected string $bar;

                        public function __construct(string $bar) {
                            $this->bar = $bar;
                        }

                        public function withBar(string $bar): self {
                            $new = clone $this;
                            /** @psalm-suppress InaccessibleProperty */
                            $new->bar = $bar;

                            return $new;
                        }
                    }'
            ],
            'allowArrayMapCallable' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class Address
                    {
                        private $line1;
                        private $line2;
                        private $city;

                        public function __construct(
                            string $line1,
                            ?string $line2,
                            string $city
                        ) {
                            $this->line1 = $line1;
                            $this->line2 = $line2;
                            $this->city = $city;
                        }

                        public function __toString()
                        {
                            $parts = [
                                $this->line1,
                                $this->line2 ?? "",
                                $this->city,
                            ];

                            // Remove empty parts
                            $parts = \array_map("trim", $parts);
                            $parts = \array_filter($parts, "strlen");
                            $parts = \array_map(function(string $s) { return $s;}, $parts);

                            return \implode(", ", $parts);
                        }
                    }'
            ],
            'allowPropertyAssignmentInUnserialize' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class Foo implements \Serializable {
                        /** @var string */
                        private $data;

                        public function __construct() {
                            $this->data = "Foo";
                        }

                        public function serialize() {
                            return $this->data;
                        }

                        public function unserialize($data) {
                            $this->data = $data;
                        }

                        public function getData(): string {
                            return $this->data;
                        }
                    }'
            ],
        ];
    }

    /**
     * @return iterable<string,array{string,error_message:string,2?:string[],3?:bool,4?:string}>
     */
    public function providerInvalidCodeParse()
    {
        return [
            'immutablePropertyAssignmentInternally' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class A {
                        /** @var int */
                        private $a;

                        /** @var string */
                        public $b;

                        public function __construct(int $a, string $b) {
                            $this->a = $a;
                            $this->b = $b;
                        }

                        public function setA(int $a): void {
                            $this->a = $a;
                        }
                    }',
                'error_message' => 'InaccessibleProperty',
            ],
            'immutablePropertyAssignmentExternally' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class A {
                        /** @var int */
                        private $a;

                        /** @var string */
                        public $b;

                        public function __construct(int $a, string $b) {
                            $this->a = $a;
                            $this->b = $b;
                        }
                    }

                    $a = new A(4, "hello");

                    $a->b = "goodbye";',
                'error_message' => 'InaccessibleProperty',
            ],
            'callImpureFunction' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class A {
                        /** @var int */
                        private $a;

                        /** @var string */
                        public $b;

                        public function __construct(int $a, string $b) {
                            $this->a = $a;
                            $this->b = $b;
                        }

                        public function bar() : void {
                            header("Location: https://vimeo.com");
                        }
                    }',
                'error_message' => 'ImpureFunctionCall',
            ],
            'callExternalClassMethod' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class A {
                        /** @var string */
                        public $a;

                        public function __construct(string $a) {
                            $this->a = $a;
                        }

                        public function getA() : string {
                            return $this->a;
                        }

                        public function redirectToA() : void {
                            B::setRedirectHeader($this->getA());
                        }
                    }

                    class B {
                        public static function setRedirectHeader(string $s) : void {
                            header("Location: $s");
                        }
                    }',
                'error_message' => 'ImpureMethodCall',
            ],
            'cloneMutatingClass' => [
                '<?php
                    /**
                     * @psalm-immutable
                     */
                    class Foo {
                        protected string $bar;

                        public function __construct(string $bar) {
                            $this->bar = $bar;
                        }

                        public function withBar(Bar $b): Bar {
                            $new = clone $b;
                            $b->a = $this->bar;

                            return $new;
                        }
                    }

                    class Bar {
                        public string $a = "hello";
                    }',
                'error_message' => 'ImpurePropertyAssignment',
            ],
        ];
    }
}
