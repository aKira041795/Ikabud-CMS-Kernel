import React, { useState, useEffect } from 'react'
import Home from '../pages/Home'
import Designs from '../pages/demo/Designs'
import Header from '../components/Header'
import Container from '../components/Container'

const Layout = () => {
    const [current, setCurrent] = useState(0)
    const activeNavHandler = (activeNavItem) => {
        sessionStorage.setItem('main-nav', activeNavItem);
        setCurrent(activeNavItem);
    };
    useEffect(() => {
        sessionStorage.removeItem('main-nav')
        const active = sessionStorage.getItem('main-nav');
        if (active) {
            setCurrent(+active)
        }
    }, [])
    const content = [

        <Designs />,
        <Home />


    ]
    return (
        <>
            <Header current={current} activeNavHandler={activeNavHandler} />
            <main>
                <Container>
                    {content[current]}
                </Container>
            </main>
        </>
    )
}

export default Layout
