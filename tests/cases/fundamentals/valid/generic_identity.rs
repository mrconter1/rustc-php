// exit: 0
// stdout: 42
// stdout: true

fn identity<T>(x: T) -> T {
    x
}

fn main() {
    let a = identity(42);
    println!("{}", a);
    let b = identity(true);
    if b {
        println!("true");
    }
}
