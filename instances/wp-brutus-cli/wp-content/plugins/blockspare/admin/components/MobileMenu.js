import React, {useState, useEffect } from 'react'
import { mobileMenu, menuClose } from '../data/icons'

const MobileMenu = ({ current, activeNavHandler }) => {
    const [active, setActive] = useState(false)
    const menuHandler = () => {
        setActive(!active)
    }
    const menuActiveItemHandler = (i) => {
        activeNavHandler(i)
        setActive(false)
    }
    useEffect(() => {
        if(active) {
            document.body.style.overflow = "hidden";
        }
        return () => (document.body.style.overflow = "scroll");
    },[active]);
    return (
        <>
            <div className='mobile-menu-icon' onClick={menuHandler}>
                {mobileMenu}
            </div>
            {active && (
                <div className="mobile-menu-content">
                    <div className="mobile-menu-close" onClick={() => setActive(false)}>
                        {menuClose}
                    </div>
                    <nav className="navigation-items-mobile">
                        {navs.map((item, i) => (
                            <a key={i} className={`navigation-item ${current === i ? "active" : " "}`} onClick={() => menuActiveItemHandler(i)}>{item.title}</a>
                        ))}
                    </nav>
                </div>
            )}
        </>
    )
}

export default MobileMenu


const navs = [
    {
        title: "Welcome",
    },
    // {
    //     title: "Blocks",
    // },
    {
        title: "Designs"
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