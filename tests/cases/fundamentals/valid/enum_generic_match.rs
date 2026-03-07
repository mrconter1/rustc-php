// exit: 0
// stdout: 10
// stdout: 20
// stdout: 0

enum E {
    A(i32),
    B(i32),
    C,
}

fn main() {
    let e1 = E::A(10);
    let v1 = match e1 {
        E::A(n) => n,
        E::B(n) => n * 2,
        E::C => 0,
    };
    println!("{}", v1);
    let e2 = E::B(10);
    let v2 = match e2 {
        E::A(n) => n,
        E::B(n) => n * 2,
        E::C => 0,
    };
    println!("{}", v2);
    let e3 = E::C;
    let v3 = match e3 {
        E::A(n) => n,
        E::B(n) => n * 2,
        E::C => 0,
    };
    println!("{}", v3);
}
