// error: Only 2-element tuple destructuring supported
fn main() {
    let (a, b, c) = (1, 2, 3);
    println!("{}", a + b + c);
}
