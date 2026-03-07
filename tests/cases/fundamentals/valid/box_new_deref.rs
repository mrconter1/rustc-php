// exit: 0
// stdout: 42

fn main() {
    let b: Box<i32> = Box::new(42);
    let x: i32 = *b;
    println!("{}", x);
    exit(0);
}
