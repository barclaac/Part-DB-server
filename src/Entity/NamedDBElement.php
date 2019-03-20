<?php declare(strict_types=1);
/**
 *
 * Part-DB Version 0.4+ "nextgen"
 * Copyright (C) 2016 - 2019 Jan Böhmer
 * https://github.com/jbtronics
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
 *
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * All subclasses of this class have an attribute "name".
 *
 * @ORM\MappedSuperclass()
 * @ORM\HasLifecycleCallbacks()
 */
abstract class NamedDBElement extends DBElement
{
    /**
     * @var string The name of this element.
     * @ORM\Column(type="string")
     * @Assert\NotBlank()
     *
     */
    protected $name = '';

    /**
     * @var \DateTime The date when this element was modified the last time.
     * @ORM\Column(type="datetimetz", name="last_modified")
     */
    protected $lastModified;

    /**
     * @var \DateTime The date when this element was created.
     * @ORM\Column(type="datetimetz", name="datetime_added")
     */
    protected $addedDate;

    /********************************************************************************
     *
     *   Getters
     *
     *********************************************************************************/

    /**
     * Get the name
     *
     * @return string   the name of this element
     */
    public function getName() : string
    {
        /*
        //Strip HTML from Name, so no XSS injection is possible.
        return strip_tags($this->name); */
        return $this->name;
    }

    /**
     * Returns the last time when the element was modified.
     * @return \DateTime The time of the last edit.
     */
    public function getLastModified() : \DateTime
    {
        return $this->lastModified;
    }

    /**
     * Returns the date/time when the element was created.
     * @return \DateTime The creation time of the part.
     */
    public function getAddedDate() : \DateTime
    {
        return $this->addedDate;
    }

    /********************************************************************************
     *
     *   Setters
     *
     *********************************************************************************/

    /**
     * Change the name of this element
     *
     *     Spaces at the begin and at the end of the string will be removed
     *          automatically in NamedDBElement::check_values_validity().
     *          So you don't have to do this yourself.
     *
     * @param string $new_name      the new name
     * @return self
     */
    public function setName(string $new_name) : self
    {
        $this->name = $new_name;
        return $this;
    }

    /******************************************************************************
     *
     * Helpers
     *
     ******************************************************************************/

    /**
     * Helper for updating the timestamp. It is automatically called by doctrine before persisting.
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function updatedTimestamps(): void
    {
        $this->lastModified = new \DateTime('now');
        if ($this->addedDate === null) {
            $this->addedDate = new \DateTime('now');
        }
    }

}