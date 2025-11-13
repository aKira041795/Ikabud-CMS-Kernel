import React, { useState } from 'react'

import { blocksData } from '../data/blocksData'
import { playIcon } from '../data/icons'
const { __ } = wp.i18n


const Blocks = () => {


    return (
        <div className="block-list">
            <div className="blocklist-page">
                <div className="blocklist-container">

                    <div className="blocklist-body">
                        <div className="wrapper">
                            {
                                blocksData.categories.map((category) => (
                                    <div className="block-category" kay={category.slug}>
                                        <div className="block-category-info">
                                            <h2 className="block-category-heading">{__(category.name, 'blockspare')}</h2>

                                        </div>
                                        <div className="blockspare_block_cards">
                                            {blocksData.blocks
                                                .filter((block) => block.categoryId === category.slug)
                                                .map((block) => (
                                                    <div class={`blockspare_block_card ${block.isEnabled ? "active" : ""}`}>
                                                        <div className="blockspare_block_card__icon">
                                                            {block.icon}
                                                        </div>
                                                        <div class="blockspare_block_card__info" >
                                                            <div class="blockspare_block_card__header">
                                                                <h3 class="blockspare_block_card__title">{__(block.name, 'blockspare')}</h3>
                                                            </div>
                                                            <div class="blockspare_block_card__footer">
                                                                <a href={block.demo_link} target="_blank" class="blockspare_block_card__link">{__('Live Demo', 'blockspare')}</a>
                                                                <a href={block.video_link} target="_blank" class="blockspare_block_card__video_link">
                                                                    {playIcon}
                                                                </a>

                                                            </div>
                                                        </div>
                                                    </div>
                                                ))}
                                        </div>
                                    </div>
                                ))
                            }
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}

export default Blocks

