import React from 'react'

const Navs = ({ current, activeNavHandler }) => {

    return (
        <div className="bs-tab-list">
            {navs.map((item, i) => (
                <div key={i} className={`bs-tab ${current === i ? "bs-active-tab" : " "}`} onClick={() => activeNavHandler(i)}>{item.title}</div>
            ))}
        </div>
    )
}

export default Navs


const navs = [

    // {
    //     title: "Blocks",
    // },
    {
        title: "Patterns Library"
    },
    {
        title: "Explore More",
    },
    // {
    //     title: "Settings",
    // },
    // {
    //     title: "Colors"
    // },
    // {
    //     title: "Typography"
    // }
]