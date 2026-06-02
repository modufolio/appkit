# Forms

AppKit provides two ways to validate input: the `Form` class for explicit validation, and parameter attributes (`#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapFilter]`) for automatic deserialization and validation directly in controller methods.

## The `Form` class

Extend `Modufolio\Appkit\Form\Form` and implement the `rules()` method. It returns a Symfony Validator constraint.

```php
// src/Form/CreatePostForm.php
namespace App\Form;

use Modufolio\Appkit\Form\Form;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraint;

class CreatePostForm extends Form
{
    protected function rules(): Constraint
    {
        return new Assert\Collection([
            'title' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            'body'  => [new Assert\NotBlank()],
        ]);
    }
}
```

Call `validate()` with the raw data:

```php
$form = new CreatePostForm();
$result = $form->validate($request->getParsedBody());

if ($result->hasErrors()) {
    // show errors
}
```

## `ValidationResult`

`validate()` always returns a `ValidationResult`. It is immutable.

```php
$result->isValid();          // bool
$result->hasErrors();        // bool — opposite of isValid()
$result->errors();           // array<string, string[]> — field => [messages]
$result->first('title');     // ?string — first error message for a field
$result->messages();         // string[] — flat list of all messages
$result->violations();       // Symfony ConstraintViolationListInterface
$result->throwIfFailed();    // throws ValidationException if invalid
```

Field names in `errors()` have bracket notation stripped. `[title]` becomes `title`.

## `ValidationException`

```php
try {
    $result->throwIfFailed();
} catch (\Modufolio\Appkit\Form\ValidationException $e) {
    $e->getErrors();            // same as $result->errors()
    $e->getValidationResult();  // the ValidationResult instance
}
```

The exception handler registers `ValidationException` by default and returns a `422` JSON response with the violation details.

## Mapping request payloads automatically

Use `#[MapRequestPayload]` to skip writing `$form->validate()` manually. AppKit deserialises the request body and validates it against the DTO's constraints in one step.

Deserialization is handled by **Symfony's `ObjectNormalizer`**. How it populates your DTO depends on the property visibility you choose.

### DTOs with public properties

The simplest approach. `ObjectNormalizer` writes directly to public properties — no extra methods needed.

```php
// src/Dto/CreatePostDto.php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class CreatePostDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $title = '';

    #[Assert\NotBlank]
    public string $body = '';
}
```

### DTOs with private properties

When properties are private and there is no constructor for the serializer to call, `ObjectNormalizer` falls back to setters. Without them, the properties stay at their default values and validation will silently pass on empty data.

Provide a setter for each property you want populated:

```php
// src/Dto/CreatePostDto.php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class CreatePostDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[Assert\NotBlank]
    private string $body = '';

    public function setTitle(string $title): void { $this->title = $title; }
    public function getTitle(): string { return $this->title; }

    public function setBody(string $body): void { $this->body = $body; }
    public function getBody(): string { return $this->body; }
}
```

> If your DTO has private properties and no setters, the serializer cannot populate them. The object will contain only default values and validation will run against those defaults, not the actual request data. Always use either public properties or getters/setters — not private properties alone.

### Use it in a controller

```php
use Modufolio\Appkit\Attributes\MapRequestPayload;

#[Route(path: '/api/posts', name: 'api.posts.create', methods: ['POST'])]
public function create(#[MapRequestPayload] CreatePostDto $dto): ResponseInterface
{
    // If we get here, validation passed
    $post = new Post();
    $post->setTitle($dto->title);
    $this->entityManager->persist($post);
    $this->entityManager->flush();

    return Response::json(['id' => $post->getId()], 201);
}
```

Validation failures automatically return a `422` response with error details. You never need to check `$result->hasErrors()` when `throwOnError` is true (the default).

### Handling errors without exceptions

Set `throwOnError: false` and receive a `ValidationResult` as the next parameter.

```php
public function create(
    #[MapRequestPayload(throwOnError: false)] CreatePostDto $dto,
    ValidationResult $result,
): ResponseInterface {
    if ($result->hasErrors()) {
        return Response::json(['errors' => $result->errors()], 422);
    }

    // $dto is fully populated even when throwOnError: false
    // ...
}
```

AppKit detects the `ValidationResult` type in the next parameter automatically. No extra wiring is needed.

## Mapping query strings

`#[MapQueryString]` works the same way but reads from `$request->getQueryParams()` instead of the request body.

```php
// src/Dto/SearchQuery.php
class SearchQuery
{
    #[Assert\Length(max: 100)]
    public ?string $q = null;

    #[Assert\Range(min: 1)]
    public int $page = 1;

    #[Assert\Choice(choices: ['asc', 'desc'])]
    public string $sort = 'desc';
}
```

```php
#[Route(path: '/posts', name: 'posts.index', methods: ['GET'])]
public function index(#[MapQueryString] SearchQuery $query): ResponseInterface
{
    // $query->q, $query->page, $query->sort are populated from the URL
}
```

## Filter objects

`#[MapFilter]` is designed for filter objects with a `fromArray()` static method. AppKit calls `fromArray($request->getQueryParams())` and injects the result.

```php
// src/Filter/PostFilter.php
class PostFilter
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $author = null,
    ) {}

    public static function fromArray(array $params): self
    {
        return new self(
            status: $params['status'] ?? null,
            author: $params['author'] ?? null,
        );
    }
}
```

```php
#[Route(path: '/posts', name: 'posts.index', methods: ['GET'])]
public function index(#[MapFilter] PostFilter $filter): ResponseInterface
{
    // $filter->status, $filter->author
}
```

## Symfony validator constraints

Any `symfony/validator` constraint works in DTOs and Form classes. Common ones:

```php
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\NotBlank]
#[Assert\NotNull]
#[Assert\Length(min: 3, max: 255)]
#[Assert\Email]
#[Assert\Url]
#[Assert\Range(min: 1, max: 100)]
#[Assert\Choice(choices: ['draft', 'published'])]
#[Assert\Regex(pattern: '/^[a-z0-9-]+$/')]
#[Assert\Count(min: 1, max: 10)]
```

Group multiple constraints on one property:

```php
#[Assert\NotBlank]
#[Assert\Length(min: 8)]
#[Assert\Regex(pattern: '/[A-Z]/', message: 'Must contain an uppercase letter')]
public string $password = '';
```

## Injecting a validator manually

Outside of controllers, inject `ValidatorInterface` to run validation directly.

```php
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SomeService
{
    public function __construct(private readonly ValidatorInterface $validator) {}

    public function process(MyDto $dto): ValidationResult
    {
        $violations = $this->validator->validate($dto);
        return ValidationResult::fromViolations($violations);
    }
}
```
