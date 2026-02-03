<?php

namespace Modufolio\Appkit\Tests\App\Entity;

use Modufolio\Appkit\Tests\App\Entity\SubDirectory\Category;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User
{
    #[ORM\ManyToOne(inversedBy: 'foods')]
    private ?Category $category = null;

    #[ORM\ManyToOne(inversedBy: 'foods')]
    private ?Category $subCategory = null;

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getSubCategory(): ?Category
    {
        return $this->subCategory;
    }

    public function setSubCategory(?Category $subCategory): static
    {
        $this->subCategory = $subCategory;

        return $this;
    }
}
